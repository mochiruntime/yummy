<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Enforces PUBLIC_BROWSING_ENABLED at the Symfony firewall access_control layer.
 *
 * Grants PUBLIC_BROWSING_OR_MEMBER to any authenticated User, or to unauthenticated guests
 * only when PUBLIC_BROWSING_ENABLED=true. When denied, Symfony's firewall automatically
 * delegates to ApiAuthenticationEntryPoint (HTTP 401).
 *
 * @extends Voter<string, mixed>
 */
final class PublicBrowsingVoter extends Voter
{
    public const ATTR = 'PUBLIC_BROWSING_OR_MEMBER';

    public function __construct(
        private readonly bool $publicBrowsingEnabled,
    ) {
    }

    /**
     * Supports only the PUBLIC_BROWSING_OR_MEMBER firewall attribute.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTR;
    }

    /**
     * Grants access to authenticated users or, when public browsing is enabled, to guests.
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if ($token->getUser() instanceof User) {
            return true;
        }

        return $this->publicBrowsingEnabled;
    }
}
