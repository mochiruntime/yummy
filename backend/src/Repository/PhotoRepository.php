<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Photo;
use App\Entity\Poi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for querying POI and review photos with unified gallery ordering and visibility rules.
 *
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /**
     * Retrieves photos for a POI ordered with direct POI photos first, then review-attached photos.
     *
     * For unauthenticated guests, enforces the strict parent-child visibility invariant:
     * - photo.isPublic = true
     * - AND if attached to a review (review_id IS NOT NULL), review.isPublic = true.
     *
     * @param Poi  $poi     Target POI
     * @param bool $isGuest True if viewer is unauthenticated
     *
     * @return list<Photo>
     */
    public function findVisibleForPoi(Poi $poi, bool $isGuest): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.review', 'r')
            ->addSelect('r')
            ->andWhere('p.poi = :poi')
            ->setParameter('poi', $poi->getId()->toBinary())
            // Unified gallery ordering per PRD FR-SOC-01: direct POI photos first, then review photos
            ->addSelect('CASE WHEN p.review IS NULL THEN 0 ELSE 1 END AS HIDDEN isReviewPhoto')
            ->orderBy('isReviewPhoto', 'ASC')
            ->addOrderBy('p.createdAt', 'ASC');

        if ($isGuest) {
            $qb->andWhere('p.isPublic = true')
                ->andWhere('(r.id IS NULL OR r.isPublic = true)');
        }

        /** @var list<Photo> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
