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
     * Retrieves all curated lists ordered alphabetically by list name.
     *
     * @return list<PoiList>
     */
    public function findAllOrderedByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
