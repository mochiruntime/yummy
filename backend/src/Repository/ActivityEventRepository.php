<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityEvent;
use App\Entity\Photo;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Doctrine repository for querying, syncing, and pruning chronological activity feed events.
 *
 * @extends ServiceEntityRepository<ActivityEvent>
 */
class ActivityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityEvent::class);
    }

    /**
     * Fetches a cursor-paginated slice of activity events ordered newest-first.
     *
     * Fetches ($limit + 1) rows so callers can detect whether a subsequent page exists.
     * For unauthenticated guests ($viewer === null), strictly enforces:
     * - event.isPublic = true AND poi.isPublic = true
     * - For photo.added events attached to a review, the parent review must also be public.
     * - For reaction.added events targeting a review or photo, the parent review/photo (and any owning review) must also be public.
     *
     * @param User|null               $viewer          Authenticated member, or null for guests
     * @param int                     $limit           Maximum page size (1..100)
     * @param \DateTimeImmutable|null $cursorCreatedAt Keyset cursor timestamp
     * @param Uuid|null               $cursorId        Keyset cursor event UUID tie-breaker
     *
     * @return list<ActivityEvent>
     */
    public function findFeedPage(
        ?User $viewer,
        int $limit,
        ?\DateTimeImmutable $cursorCreatedAt = null,
        ?Uuid $cursorId = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.actor', 'a')
            ->innerJoin('e.poi', 'p')
            ->addSelect('a', 'p')
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit + 1);

        if ($viewer === null) {
            // Left-join Photo, Review, and Reaction targets so guests never see public events
            // whose parent Review or Photo is private.
            $qb->leftJoin(Photo::class, 'ph', 'WITH', 'e.targetType = :photoTarget AND e.targetId = ph.id')
                ->leftJoin('ph.review', 'phRev')
                ->leftJoin(Reaction::class, 'rx', 'WITH', 'e.targetType = :reactionTarget AND e.targetId = rx.id')
                ->leftJoin('rx.review', 'rxRev')
                ->leftJoin('rx.photo', 'rxPh')
                ->leftJoin('rxPh.review', 'rxPhRev')
                ->andWhere('e.isPublic = true')
                ->andWhere('p.isPublic = true')
                ->andWhere('ph.id IS NULL OR (ph.isPublic = true AND (phRev.id IS NULL OR phRev.isPublic = true))')
                ->andWhere('rx.id IS NULL OR ((rxRev.id IS NULL OR rxRev.isPublic = true) AND (rxPh.id IS NULL OR (rxPh.isPublic = true AND (rxPhRev.id IS NULL OR rxPhRev.isPublic = true))))')
                ->setParameter('photoTarget', ActivityEvent::TARGET_PHOTO)
                ->setParameter('reactionTarget', ActivityEvent::TARGET_REACTION);
        }

        if ($cursorCreatedAt !== null && $cursorId !== null) {
            // Deterministic keyset pagination using (createdAt, id) descending.
            $qb->andWhere('(e.createdAt < :cursorCreatedAt OR (e.createdAt = :cursorCreatedAt AND e.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursorCreatedAt)
                ->setParameter('cursorId', $cursorId->toBinary());
        }

        /** @var list<ActivityEvent> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Synchronizes the denormalized isPublic flag for all events matching a target entity.
     *
     * @param string $targetType Target discriminator (ActivityEvent::TARGET_*)
     * @param Uuid   $targetId   Target entity UUID
     * @param bool   $isPublic   New public visibility flag
     */
    public function updateVisibilityByTarget(string $targetType, Uuid $targetId, bool $isPublic): int
    {
        /** @var int $updated */
        $updated = $this->createQueryBuilder('e')
            ->update()
            ->set('e.isPublic', ':isPublic')
            ->andWhere('e.targetType = :targetType')
            ->andWhere('e.targetId = :targetId')
            ->setParameter('isPublic', $isPublic)
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId->toBinary())
            ->getQuery()
            ->execute();

        return $updated;
    }

    /**
     * Deletes all activity events referencing a deleted or withdrawn target entity.
     *
     * @param string $targetType Target discriminator (ActivityEvent::TARGET_*)
     * @param Uuid   $targetId   Target entity UUID
     */
    public function deleteByTarget(string $targetType, Uuid $targetId): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.targetType = :targetType')
            ->andWhere('e.targetId = :targetId')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId->toBinary())
            ->getQuery()
            ->execute();

        return $deleted;
    }

    /**
     * Deletes all activity events referencing any of the given target entity UUIDs.
     *
     * @param string     $targetType Target discriminator (ActivityEvent::TARGET_*)
     * @param list<Uuid> $targetIds  Target entity UUIDs
     */
    public function deleteByTargets(string $targetType, array $targetIds): int
    {
        if ($targetIds === []) {
            return 0;
        }

        // Convert UUID objects to raw 16-byte binary strings for exact BINARY(16) IN (:targetIds) matching.
        $binaryIds = array_map(static fn (Uuid $id): string => $id->toBinary(), $targetIds);

        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.targetType = :targetType')
            ->andWhere('e.targetId IN (:targetIds)')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetIds', $binaryIds)
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
