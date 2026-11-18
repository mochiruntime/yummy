<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Doctrine repository for managing and aggregating emoji reactions on POIs, Reviews, and Photos.
 *
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    /**
     * Finds an existing reaction matching the unique (targetType, targetId, user, emoji) tuple.
     *
     * @param string $targetType Target discriminator ('poi', 'review', or 'photo')
     * @param Uuid   $targetId   Target entity UUID
     * @param User   $user       Reacting user
     * @param string $emoji      Emoji string
     */
    public function findOneByTargetUserAndEmoji(
        string $targetType,
        Uuid $targetId,
        User $user,
        string $emoji,
    ): ?Reaction {
        /** @var ?Reaction $reaction */
        $reaction = $this->createQueryBuilder('r')
            ->andWhere('r.targetType = :targetType')
            ->andWhere('r.targetId = :targetId')
            ->andWhere('r.user = :user')
            ->andWhere('r.emoji = :emoji')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId->toBinary())
            ->setParameter('user', $user->getId()->toBinary())
            ->setParameter('emoji', trim($emoji))
            ->getQuery()
            ->getOneOrNullResult();

        return $reaction;
    }

    /**
     * Aggregates reactions for a single target entity (POI, Review, or Photo).
     *
     * @param string    $targetType Target discriminator ('poi', 'review', or 'photo')
     * @param Uuid      $targetId   Target entity UUID
     * @param User|null $viewer     Current authenticated user, or null for guests
     *
     * @return list<array{emoji: string, count: int, reactedByMe: bool}>
     */
    public function getAggregatedForTarget(string $targetType, Uuid $targetId, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')
            ->addSelect('u')
            ->andWhere('r.targetType = :targetType')
            ->andWhere('r.targetId = :targetId')
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId->toBinary())
            ->orderBy('r.createdAt', 'ASC');

        if ($viewer === null) {
            $qb->andWhere('r.isPublic = true');
        }

        /** @var list<Reaction> $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->aggregateRows($rows, $viewer);
    }

    /**
     * Aggregates direct POI-level reactions by emoji, respecting guest visibility.
     *
     * @param Poi       $poi    Target POI
     * @param User|null $viewer Current authenticated user, or null for guests
     *
     * @return list<array{emoji: string, count: int, reactedByMe: bool}>
     */
    public function getAggregatedForPoi(Poi $poi, ?User $viewer): array
    {
        return $this->getAggregatedForTarget(Reaction::TARGET_POI, $poi->getId(), $viewer);
    }

    /**
     * Aggregates reactions for all reviews belonging to a POI in a single query, keyed by review UUID.
     *
     * @param Poi       $poi    Parent POI
     * @param User|null $viewer Current authenticated user, or null for guests
     *
     * @return array<string, list<array{emoji: string, count: int, reactedByMe: bool}>>
     */
    public function getAggregatedForReviews(Poi $poi, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')
            ->addSelect('u')
            ->innerJoin('r.review', 'rev')
            ->andWhere('r.poi = :poi')
            ->andWhere('r.targetType = :targetType')
            ->setParameter('poi', $poi->getId()->toBinary())
            ->setParameter('targetType', Reaction::TARGET_REVIEW)
            ->orderBy('r.createdAt', 'ASC');

        if ($viewer === null) {
            $qb->andWhere('r.isPublic = true')
                ->andWhere('rev.isPublic = true');
        }

        /** @var list<Reaction> $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->groupAndAggregateByTarget($rows, $viewer);
    }

    /**
     * Aggregates reactions for all photos belonging to a POI in a single query, keyed by photo UUID.
     *
     * @param Poi       $poi    Parent POI
     * @param User|null $viewer Current authenticated user, or null for guests
     *
     * @return array<string, list<array{emoji: string, count: int, reactedByMe: bool}>>
     */
    public function getAggregatedForPhotos(Poi $poi, ?User $viewer): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')
            ->addSelect('u')
            ->innerJoin('r.photo', 'ph')
            ->leftJoin('ph.review', 'phRev')
            ->andWhere('r.poi = :poi')
            ->andWhere('r.targetType = :targetType')
            ->setParameter('poi', $poi->getId()->toBinary())
            ->setParameter('targetType', Reaction::TARGET_PHOTO)
            ->orderBy('r.createdAt', 'ASC');

        if ($viewer === null) {
            $qb->andWhere('r.isPublic = true')
                ->andWhere('ph.isPublic = true')
                ->andWhere('phRev.id IS NULL OR phRev.isPublic = true');
        }

        /** @var list<Reaction> $rows */
        $rows = $qb->getQuery()->getResult();

        return $this->groupAndAggregateByTarget($rows, $viewer);
    }

    /**
     * Groups reaction rows by target UUID string and aggregates each group by emoji.
     *
     * @param list<Reaction> $rows   Fetched reaction entities
     * @param User|null      $viewer Current authenticated user, or null for guests
     *
     * @return array<string, list<array{emoji: string, count: int, reactedByMe: bool}>>
     */
    private function groupAndAggregateByTarget(array $rows, ?User $viewer): array
    {
        /** @var array<string, list<Reaction>> $byTarget */
        $byTarget = [];

        foreach ($rows as $reaction) {
            $targetKey = $reaction->getTargetId()->toRfc4122();
            $byTarget[$targetKey][] = $reaction;
        }

        $result = [];
        foreach ($byTarget as $targetKey => $targetRows) {
            $result[$targetKey] = $this->aggregateRows($targetRows, $viewer);
        }

        return $result;
    }

    /**
     * Aggregates a flat list of reactions for a single target into ordered emoji summary items.
     *
     * @param list<Reaction> $rows   Reactions for one target
     * @param User|null      $viewer Current authenticated user, or null for guests
     *
     * @return list<array{emoji: string, count: int, reactedByMe: bool}>
     */
    private function aggregateRows(array $rows, ?User $viewer): array
    {
        $viewerId = $viewer?->getId()->toRfc4122();
        /** @var array<string, array{emoji: string, count: int, reactedByMe: bool}> $grouped */
        $grouped = [];

        foreach ($rows as $reaction) {
            $emoji = $reaction->getEmoji();
            if (!isset($grouped[$emoji])) {
                $grouped[$emoji] = [
                    'emoji' => $emoji,
                    'count' => 0,
                    'reactedByMe' => false,
                ];
            }

            ++$grouped[$emoji]['count'];
            if ($viewerId !== null && $reaction->getUser()->getId()->toRfc4122() === $viewerId) {
                $grouped[$emoji]['reactedByMe'] = true;
            }
        }

        return array_values($grouped);
    }
}
