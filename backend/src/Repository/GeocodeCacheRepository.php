<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GeocodeCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for looking up and storing cached place search autocomplete results.
 *
 * @extends ServiceEntityRepository<GeocodeCache>
 */
class GeocodeCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeocodeCache::class);
    }

    /**
     * Finds a cached geocode query by its SHA-256 hash key, returning null if not found or expired.
     *
     * @param string                  $queryHash SHA-256 query hash
     * @param \DateTimeImmutable|null $now       Reference time for expiration check (defaults to current time)
     */
    public function findValid(string $queryHash, ?\DateTimeImmutable $now = null): ?GeocodeCache
    {
        $cached = $this->find($queryHash);
        if ($cached === null) {
            return null;
        }

        $referenceTime = $now ?? new \DateTimeImmutable();
        if ($cached->isExpired($referenceTime)) {
            return null;
        }

        return $cached;
    }
}
