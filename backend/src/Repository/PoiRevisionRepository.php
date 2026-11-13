<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\PoiRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for querying immutable audit revisions and state history of POIs.
 *
 * @extends ServiceEntityRepository<PoiRevision>
 */
class PoiRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoiRevision::class);
    }

    /**
     * Retrieves all recorded revisions for a given POI in reverse chronological order (newest first).
     *
     * @return list<PoiRevision>
     */
    public function findByPoiOrderedByRevisionDesc(Poi $poi): array
    {
        return $this->findBy(['poi' => $poi], ['revisionNumber' => 'DESC']);
    }

    /**
     * Finds a specific revision of a POI by its sequential revision number.
     *
     * @param Poi $poi            Target POI
     * @param int $revisionNumber Sequential revision counter
     */
    public function findOneByPoiAndRevision(Poi $poi, int $revisionNumber): ?PoiRevision
    {
        return $this->findOneBy(['poi' => $poi, 'revisionNumber' => $revisionNumber]);
    }
}
