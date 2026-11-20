<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Review;
use App\Entity\ReviewItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for querying ReviewItem entities without N+1 queries.
 *
 * @extends ServiceEntityRepository<ReviewItem>
 */
class ReviewItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReviewItem::class);
    }

    /**
     * Batch-loads all ReviewItem entities for the given reviews, grouped by review RFC 4122 UUID string.
     *
     * @param list<Review> $reviews Visible reviews for a POI
     *
     * @return array<string, list<ReviewItem>>
     */
    public function findGroupedByReviews(array $reviews): array
    {
        if ($reviews === []) {
            return [];
        }

        // Convert Symfony UUIDs to raw 16-byte binary strings for MariaDB BINARY(16) IN (:ids) matching.
        $binaryIds = array_map(
            static fn (Review $review): string => $review->getId()->toBinary(),
            $reviews,
        );

        /** @var list<ReviewItem> $items */
        $items = $this->createQueryBuilder('ri')
            ->where('ri.review IN (:reviewIds)')
            ->setParameter('reviewIds', $binaryIds)
            ->orderBy('ri.sortOrder', 'ASC')
            ->addOrderBy('ri.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($items as $item) {
            $reviewId = $item->getReview()->getId()->toRfc4122();
            $grouped[$reviewId][] = $item;
        }

        return $grouped;
    }
}
