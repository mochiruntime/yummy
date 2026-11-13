<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\PoiRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PoiRevision>
 */
class PoiRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoiRevision::class);
    }

    /**
     * @return list<PoiRevision>
     */
    public function findByPoiOrderedByRevisionDesc(Poi $poi): array
    {
        return $this->findBy(['poi' => $poi], ['revisionNumber' => 'DESC']);
    }

    public function findOneByPoiAndRevision(Poi $poi, int $revisionNumber): ?PoiRevision
    {
        return $this->findOneBy(['poi' => $poi, 'revisionNumber' => $revisionNumber]);
    }
}
