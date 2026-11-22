<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PoiList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for querying and managing curated POI lists / categories.
 *
 * @extends ServiceEntityRepository<PoiList>
 */
class PoiListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoiList::class);
    }

    /**
     * Retrieves curated lists ordered alphabetically by list name.
     *
     * @param bool $publicOnly When true, restricts results to lists marked public
     *
     * @return list<PoiList>
     */
    public function findAllOrderedByName(bool $publicOnly = false): array
    {
        $criteria = $publicOnly ? ['isPublic' => true] : [];

        return $this->findBy($criteria, ['name' => 'ASC']);
    }
}
