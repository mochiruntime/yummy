<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for querying and managing POI reviews.
 *
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Retrieves reviews for a given POI ordered newest-first, enforcing guest visibility.
     *
     * @param Poi  $poi     Target POI
     * @param bool $isGuest True if the viewer is unauthenticated (filters to isPublic = true)
     *
     * @return list<Review>
     */
    public function findVisibleForPoi(Poi $poi, bool $isGuest): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.author', 'a')
            ->addSelect('a')
            ->andWhere('r.poi = :poi')
            ->setParameter('poi', $poi->getId()->toBinary())
            ->orderBy('r.createdAt', 'DESC');

        if ($isGuest) {
            $qb->andWhere('r.isPublic = true');
        }

        /** @var list<Review> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
