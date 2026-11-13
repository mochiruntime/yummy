<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GeocodeCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GeocodeCache>
 */
class GeocodeCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeocodeCache::class);
    }

    public function findValid(string $queryHash, \DateTimeImmutable $now = new \DateTimeImmutable()): ?GeocodeCache
    {
        $cached = $this->find($queryHash);
        if ($cached === null || $cached->isExpired($now)) {
            return null;
        }

        return $cached;
    }
}
