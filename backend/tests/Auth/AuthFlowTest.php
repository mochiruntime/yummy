<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

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

    public function testPublicConfigDefaults(): void
    {
        $this->client->request('GET', '/api/config');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertFalse($data['googleMapsEnabled']);
        self::assertNull($data['googleMapsApiKey']);
        self::assertSame('photon', $data['geocoderProvider']);
    }

    public function testCsrfProtectionBlocksMissingHeader(): void
    {
        $this->client->request('POST', '/api/auth/magic-link', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => 'x@example.com'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

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
            '{}',
        );
        self::assertResponseStatusCodeSame(201);
        $inviteData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($inviteData);
        self::assertMatchesRegularExpression('#/join/([a-f0-9]{64})$#', (string) $inviteData['inviteUrl']);
        $matched = preg_match('#/join/([a-f0-9]{64})$#', (string) $inviteData['inviteUrl'], $matches);
        self::assertSame(1, $matched);
        $rawInviteToken = $matches[1];

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
    }
}
