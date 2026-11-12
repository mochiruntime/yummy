<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InviteToken;
use App\Entity\MagicLinkToken;
use App\Entity\User;
use App\Repository\InviteTokenRepository;
use App\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use App\Routing\FrontendUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Handles cryptographically secure token lifecycle for magic links and invites.
 *
 * Security Model:
 * 1. Tokens are generated using 256 bits of CSPRNG entropy via random_bytes(32).
 * 2. Only the SHA-256 hash of the raw token is persisted to the database.
 * 3. The raw token is exposed only to the user/recipient via email or generated URL.
 * 4. Tokens are strictly single-use and invalidated immediately upon consumption.
 */
final class AuthTokenService
{
    private const MAGIC_LINK_TTL = '+15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly InviteTokenRepository $invites,
        private readonly MagicLinkTokenRepository $magicLinks,
        private readonly MailerInterface $mailer,
        private readonly FrontendUrlGenerator $urlGenerator,
        private readonly string $appMailerSender,
    ) {
    }

    /**
     * Generates a 15-minute single-use sign-in link and emails it to the user.
     *
     * If no admins exist in the instance and the requested email matches INITIAL_ADMIN_EMAIL,
     * the initial Admin account is automatically provisioned and dispatched their verification link.
     */
    public function requestMagicLink(string $email): void
    {
        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail === '') {
            return;
        }

        $user = $this->users->findOneByEmail($normalizedEmail);
        if ($user === null) {
            $user = $this->tryBootstrapInitialAdmin($normalizedEmail);
        }

        if ($user === null) {
            return;
        }

        // Generate 256-bit cryptographically secure pseudorandom token
        $rawToken = bin2hex(random_bytes(32));
        $magicLink = new MagicLinkToken(
            $user,
            hash('sha256', $rawToken),
            new \DateTimeImmutable(self::MAGIC_LINK_TTL),
        );

        $this->em->persist($magicLink);
        $this->em->flush();

        $loginUrl = $this->urlGenerator->generateMagicLinkUrl($rawToken);

        $message = (new Email())
            ->from($this->appMailerSender)
            ->to($user->getEmail())
            ->subject('Your yummy sign-in link')
            ->text("Hi {$user->getDisplayName()},\n\nSign in to yummy using this one-time link (valid for 15 minutes):\n\n{$loginUrl}\n");

        $this->mailer->send($message);
    }

    /**
     * Verifies and consumes a raw magic-link token, returning the authenticated User.
     */
    public function consumeMagicLink(string $rawToken): ?User
    {
        $token = $this->magicLinks->findOneByRawToken($rawToken);
        if ($token === null || !$token->isUsable()) {
            return null;
        }

        $token->markUsed();
        $this->em->flush();

        return $token->getUser();
    }

    /**
     * Generates a single-use friend invite token.
     *
     * @param User $admin          The Admin issuing the invite
     * @param int  $expiresInHours Duration in hours before the invite expires (default 7 days)
     *
     * @return array{invite: InviteToken, rawToken: string, inviteUrl: string}
     */
    public function createInvite(User $admin, int $expiresInHours = 168): array
    {
        // Generate 256-bit cryptographically secure pseudorandom token
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', max(1, $expiresInHours)));

        $invite = new InviteToken(hash('sha256', $rawToken), $admin, $expiresAt);
        $this->em->persist($invite);
        $this->em->flush();

        $inviteUrl = $this->urlGenerator->generateInviteUrl($rawToken);

        return [
            'invite' => $invite,
            'rawToken' => $rawToken,
            'inviteUrl' => $inviteUrl,
        ];
    }

    /**
     * Validates an invite token and provisions a new member account.
     */
    public function claimInvite(string $rawToken, string $displayName, string $email): ?User
    {
        $invite = $this->invites->findOneByRawToken($rawToken);
        if ($invite === null || !$invite->isClaimable()) {
            return null;
        }

        $normalizedEmail = mb_strtolower(trim($email));
        if ($this->users->findOneByEmail($normalizedEmail) !== null) {
            return null;
        }

        $user = new User($normalizedEmail, $displayName, ['ROLE_USER']);
        $invite->markClaimed($user);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Checks if the instance has 0 admins and matches the configured INITIAL_ADMIN_EMAIL.
     */
    private function tryBootstrapInitialAdmin(string $normalizedEmail): ?User
    {
        $configuredAdminEmail = mb_strtolower(trim((string) ($_ENV['INITIAL_ADMIN_EMAIL'] ?? '')));
        // Defensive constant-time string comparison to prevent side-channel timing leaks
        if ($configuredAdminEmail === '' || !hash_equals($configuredAdminEmail, $normalizedEmail)) {
            return null;
        }

        // Bootstrap is only permitted when zero admins exist in the system
        if ($this->users->countAdmins() > 0) {
            return null;
        }

        $adminName = trim((string) ($_ENV['INITIAL_ADMIN_NAME'] ?? 'Admin'));
        if ($adminName === '') {
            $adminName = 'Admin';
        }

        $admin = new User($normalizedEmail, $adminName, ['ROLE_ADMIN', 'ROLE_USER']);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
