<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\InviteToken;
use App\Entity\MagicLinkToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite covering public bootstrap config, CSRF protection,
 * magic-link authentication, and friend invite lifecycle with all edge cases.
 */
final class AuthFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->truncateAuthTables();
    }

    /**
     * Verifies public bootstrap configuration defaults.
     */
    public function testPublicConfigDefaults(): void
    {
        $this->client->request('GET', '/api/config');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['googleMapsEnabled']);
        self::assertNull($data['googleMapsApiKey']);
        self::assertSame('photon', $data['geocoderProvider']);
        self::assertTrue($data['publicBrowsingEnabled']);
    }

    /**
     * Verifies that state-changing requests without matching XSRF-TOKEN header are rejected.
     */
    public function testCsrfProtectionBlocksMissingHeader(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/magic-link',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Verifies INITIAL_ADMIN_EMAIL config bootstrap, email dispatch,
     * token verification, session establishment, and single-use invalidation.
     */
    public function testInitialAdminBootstrapMagicLinkAndSession(): void
    {
        $_ENV['INITIAL_ADMIN_EMAIL'] = 'admin@yummy.test';
        $_ENV['INITIAL_ADMIN_NAME'] = 'Chief Foodie';

        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/auth/magic-link',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['email' => 'admin@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $body = (string) $email->getTextBody();
        self::assertMatchesRegularExpression('#/magic-link/([a-f0-9]{64})#', $body);
        $matched = preg_match('#/magic-link/([a-f0-9]{64})#', $body, $matches);
        self::assertSame(1, $matched);
        $rawToken = $matches[1];

        // Verify magic link establishes session
        $this->client->request(
            'POST',
            '/api/auth/magic-link/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['token' => $rawToken], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $user = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($user);
        self::assertSame('admin@yummy.test', $user['email']);
        self::assertContains('ROLE_ADMIN', $user['roles']);

        // Subsequent /me is authenticated
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        // Reusing magic link fails
        $this->client->request(
            'POST',
            '/api/auth/magic-link/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['token' => $rawToken], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Verifies that expired magic links are rejected.
     */
    public function testExpiredMagicLinkIsRejected(): void
    {
        $user = new User('member@yummy.test', 'Member');
        $this->em->persist($user);

        $rawToken = bin2hex(random_bytes(32));
        // Expired 1 hour ago
        $expiredToken = new MagicLinkToken(
            $user,
            hash('sha256', $rawToken),
            new \DateTimeImmutable('-1 hour'),
        );
        $this->em->persist($expiredToken);
        $this->em->flush();

        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/auth/magic-link/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['token' => $rawToken], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Verifies magic link rate limiting (5 requests per 15 minutes).
     */
    public function testMagicLinkRequestRateLimiting(): void
    {
        $csrf = $this->fetchCsrfToken();
        $payload = json_encode(['email' => 'rate@yummy.test'], JSON_THROW_ON_ERROR);

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request(
                'POST',
                '/api/auth/magic-link',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
                $payload,
            );
            self::assertResponseStatusCodeSame(202);
        }

        // 6th attempt is throttled
        $this->client->request(
            'POST',
            '/api/auth/magic-link',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            $payload,
        );
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Verifies full invite lifecycle: creation, listing, claim, and rejection of re-claim.
     */
    public function testOneTimeInviteLifecycle(): void
    {
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
        $csrf = $this->fetchCsrfToken();

        // Admin creates one-time invite
        $this->client->request(
            'POST',
            '/api/auth/invites',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['expiresInHours' => 48], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $inviteData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($inviteData);
        self::assertMatchesRegularExpression('#/join/([a-f0-9]{64})$#', (string) $inviteData['inviteUrl']);
        $matched = preg_match('#/join/([a-f0-9]{64})$#', (string) $inviteData['inviteUrl'], $matches);
        self::assertSame(1, $matched);
        $rawInviteToken = $matches[1];

        // Admin lists invites
        $this->client->request('GET', '/api/auth/invites');
        self::assertResponseIsSuccessful();
        $invitesList = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($invitesList);
        self::assertCount(1, $invitesList);
        self::assertSame('pending', $invitesList[0]['status']);

        // Logout admin
        $this->client->request('POST', '/api/auth/logout', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        // Friend claims invite
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/auth/invites/'.$rawInviteToken.'/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['displayName' => 'Alice', 'email' => 'alice@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $friend = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($friend);
        self::assertSame('Alice', $friend['displayName']);
        self::assertNotContains('ROLE_ADMIN', $friend['roles']);

        // Friend cannot create invites (403)
        $this->client->request(
            'POST',
            '/api/auth/invites',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            '{}',
        );
        self::assertResponseStatusCodeSame(403);

        // Re-claiming the same invite fails
        $this->client->request(
            'POST',
            '/api/auth/invites/'.$rawInviteToken.'/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['displayName' => 'Bob', 'email' => 'bob@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Verifies admin revocation of pending invites.
     */
    public function testAdminCanRevokeInvite(): void
    {
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($admin);

        $rawToken = bin2hex(random_bytes(32));
        $invite = new InviteToken(hash('sha256', $rawToken), $admin, new \DateTimeImmutable('+7 days'));
        $this->em->persist($invite);
        $this->em->flush();

        $this->client->loginUser($admin);
        $csrf = $this->fetchCsrfToken();

        // Revoke invite
        $this->client->request(
            'DELETE',
            '/api/auth/invites/'.$invite->getId()->toRfc4122(),
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        // Attempting to claim revoked invite fails
        $this->client->request('POST', '/api/auth/logout', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/auth/invites/'.$rawToken.'/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['displayName' => 'Charlie', 'email' => 'charlie@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);

        // Revoking unknown ID returns 404
        $this->client->loginUser($admin);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'DELETE',
            '/api/auth/invites/'.Uuid::v7()->toRfc4122(),
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Verifies that claiming an expired invite fails.
     */
    public function testClaimingExpiredInviteFails(): void
    {
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($admin);

        $rawToken = bin2hex(random_bytes(32));
        // Expired 2 hours ago
        $invite = new InviteToken(hash('sha256', $rawToken), $admin, new \DateTimeImmutable('-2 hours'));
        $this->em->persist($invite);
        $this->em->flush();

        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/auth/invites/'.$rawToken.'/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['displayName' => 'David', 'email' => 'david@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Verifies that claiming an invite with an already-registered email fails.
     */
    public function testClaimingInviteWithExistingEmailFails(): void
    {
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $existing = new User('existing@yummy.test', 'Existing');
        $this->em->persist($admin);
        $this->em->persist($existing);

        $rawToken = bin2hex(random_bytes(32));
        $invite = new InviteToken(hash('sha256', $rawToken), $admin, new \DateTimeImmutable('+7 days'));
        $this->em->persist($invite);
        $this->em->flush();

        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/auth/invites/'.$rawToken.'/claim',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['displayName' => 'Duplicate', 'email' => 'existing@yummy.test'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(400);
    }

    private function fetchCsrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        self::assertResponseStatusCodeSame(204);

        $cookie = $this->client->getCookieJar()->get('XSRF-TOKEN');
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie->getValue();
    }

    private function truncateAuthTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM magic_link_tokens');
        $conn->executeStatement('DELETE FROM invite_tokens');
        $conn->executeStatement('DELETE FROM users');

        // Reset rate limiter cache so tests have clean rate windows
        $cache = static::getContainer()->get('cache.rate_limiter');
        if ($cache instanceof \Psr\Cache\CacheItemPoolInterface) {
            $cache->clear();
        }
    }
}
