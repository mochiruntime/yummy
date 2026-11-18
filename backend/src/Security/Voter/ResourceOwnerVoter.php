<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\Poi;
use App\Entity\PoiList;
use App\Entity\Review;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Centralizes ownership and admin authorization checks across mutable domain entities.
 *
 * Grants RESOURCE_MANAGE to any ROLE_ADMIN or the owning user of a Poi, PoiList,
 * Review, Comment, or Photo.
 *
 * @extends Voter<string, Poi|PoiList|Review|Comment|Photo>
 */
final class ResourceOwnerVoter extends Voter
{
    public const MANAGE = 'RESOURCE_MANAGE';

    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Determines whether this voter handles the requested attribute and subject type.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute !== self::MANAGE) {
            return false;
        }

        return $subject instanceof Poi
            || $subject instanceof PoiList
            || $subject instanceof Review
            || $subject instanceof Comment
            || $subject instanceof Photo;
    }

    /**
     * Grants access if the authenticated user is an admin or the subject's owner/author.
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $owner = match (true) {
            $subject instanceof Poi => $subject->getCreatedBy(),
            $subject instanceof PoiList => $subject->getOwner(),
            $subject instanceof Photo => $subject->getUploader(),
            $subject instanceof Review,
            $subject instanceof Comment => $subject->getAuthor(),
        };

        return $owner->getId()->toRfc4122() === $user->getId()->toRfc4122();
    }
}
