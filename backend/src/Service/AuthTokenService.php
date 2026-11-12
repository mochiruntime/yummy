<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InviteToken;
use App\Entity\MagicLinkToken;
use App\Entity\User;
use App\Repository\InviteTokenRepository;
use App\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class AuthTokenService
{
    private const MAGIC_LINK_TTL = '+15 minutes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly InviteTokenRepository $invites,
        private readonly MagicLinkTokenRepository $magicLinks,
        private readonly MailerInterface $mailer,
    ) {
    }

    public function requestMagicLink(string $email, string $appBaseUrl): void
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

        $rawToken = bin2hex(random_bytes(32));
        $magicLink = new MagicLinkToken(
            $user,
            hash('sha256', $rawToken),
            new \DateTimeImmutable(self::MAGIC_LINK_TTL),
        );

        $this->em->persist($magicLink);
        $this->em->flush();

        $loginUrl = rtrim($appBaseUrl, '/').'/magic-link/'.$rawToken;

        $message = (new Email())
            ->from('noreply@yummy.local')
            ->to($user->getEmail())
            ->subject('Your yummy sign-in link')
            ->text("Hi {$user->getDisplayName()},\n\nSign in to yummy using this one-time link (valid for 15 minutes):\n\n{$loginUrl}\n");

        $this->mailer->send($message);
    }

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
     * @return array{invite: InviteToken, rawToken: string}
     */
    public function createInvite(User $admin, int $expiresInHours = 168): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', max(1, $expiresInHours)));

        $invite = new InviteToken(hash('sha256', $rawToken), $admin, $expiresAt);
        $this->em->persist($invite);
        $this->em->flush();

        return ['invite' => $invite, 'rawToken' => $rawToken];
    }

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

    private function tryBootstrapInitialAdmin(string $normalizedEmail): ?User
    {
        $configuredAdminEmail = mb_strtolower(trim((string) ($_ENV['INITIAL_ADMIN_EMAIL'] ?? '')));
        if ($configuredAdminEmail === '' || !hash_equals($configuredAdminEmail, $normalizedEmail)) {
            return null;
        }

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
