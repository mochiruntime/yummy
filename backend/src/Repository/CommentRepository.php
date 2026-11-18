<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Poi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for querying and managing POI discussion comments.
 *
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Retrieves comments for a given POI ordered chronologically (oldest-first), enforcing guest visibility.
     *
     * @param Poi  $poi     Target POI
     * @param bool $isGuest True if the viewer is unauthenticated (filters to isPublic = true)
     *
     * @return list<Comment>
     */
    public function findVisibleForPoi(Poi $poi, bool $isGuest): array
    {
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.author', 'a')
            ->addSelect('a')
            ->andWhere('c.poi = :poi')
            ->setParameter('poi', $poi->getId()->toBinary())
            ->orderBy('c.createdAt', 'ASC');

        if ($isGuest) {
            $qb->andWhere('c.isPublic = true');
        }

        /** @var list<Comment> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
