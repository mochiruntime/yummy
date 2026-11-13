<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Poi>
 */
class PoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Poi::class);
    }

    /**
     * Finds POIs matching bounding-box and list filters, enforcing public visibility for guests.
     *
     * @param array{minLng: float, minLat: float, maxLng: float, maxLat: float}|null $bbox
     * @param list<string>                                                           $listIds
     *
     * @return list<Poi>
     */
    public function findByFilters(?array $bbox, array $listIds, bool $isGuest): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.lists', 'l')
            ->addSelect('l')
            ->orderBy('p.createdAt', 'DESC');

        if ($isGuest) {
            $qb->andWhere('p.isPublic = true');
        }

        if ($bbox !== null) {
            // Using MariaDB bounding-box check
            $qb->andWhere('p.latitude >= :minLat AND p.latitude <= :maxLat AND p.longitude >= :minLng AND p.longitude <= :maxLng')
                ->setParameter('minLat', sprintf('%.7f', $bbox['minLat']))
                ->setParameter('maxLat', sprintf('%.7f', $bbox['maxLat']))
                ->setParameter('minLng', sprintf('%.7f', $bbox['minLng']))
                ->setParameter('maxLng', sprintf('%.7f', $bbox['maxLng']));
        }

        if ($listIds !== []) {
            $validUuids = array_values(array_filter($listIds, static fn (string $id): bool => Uuid::isValid($id)));
            if ($validUuids !== []) {
                $qb->andWhere('l.id IN (:listIds)')
                    ->setParameter('listIds', array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $validUuids));
            }
        }

        /** @var list<Poi> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
