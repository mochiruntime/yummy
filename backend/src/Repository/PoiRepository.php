<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Doctrine repository for querying, filtering, and persisting Point of Interest (POI) entities.
 *
 * Provides specialized geospatial bounding-box queries, list membership filters, and guest-scoping.
 *
 * @extends ServiceEntityRepository<Poi>
 */
class PoiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Poi::class);
    }

    /**
     * Finds POIs matching optional bounding-box coordinates and list filters, enforcing guest visibility.
     *
     * @param array{minLng: float, minLat: float, maxLng: float, maxLat: float}|null $bbox    Viewport bounding box
     * @param list<string>                                                           $listIds Category/list filter IDs
     * @param bool                                                                   $isGuest Whether the viewer is unauthenticated
     *
     * @return list<Poi>
     */
    public function findByFilters(?array $bbox, array $listIds, bool $isGuest): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.lists', 'l')
            ->addSelect('l')
            ->orderBy('p.createdAt', 'DESC');

        // Unauthenticated guests may only view POIs explicitly marked as public by their creators.
        // Authenticated members are permitted to view both public POIs and instance-private POIs.
        if ($isGuest) {
            $qb->andWhere('p.isPublic = true');
        }

        // Apply rectangular bounding-box (viewport) filter if provided by the frontend map canvas.
        if ($bbox !== null) {
            $qb->andWhere('p.latitude >= :minLat AND p.latitude <= :maxLat AND p.longitude >= :minLng AND p.longitude <= :maxLng')
                ->setParameter('minLat', sprintf('%.7f', $bbox['minLat']))
                ->setParameter('maxLat', sprintf('%.7f', $bbox['maxLat']))
                ->setParameter('minLng', sprintf('%.7f', $bbox['minLng']))
                ->setParameter('maxLng', sprintf('%.7f', $bbox['maxLng']));
        }

        // Filter by curated lists if any list IDs were requested.
        if ($listIds !== []) {
            $validUuids = array_values(array_filter($listIds, static fn (string $id): bool => Uuid::isValid($id)));
            if ($validUuids !== []) {
                // MariaDB stores Symfony UUIDs as BINARY(16). When performing an 'IN (:listIds)' check
                // in DQL, passing strings causes type mismatches because Doctrine does not auto-cast
                // arrays of UUID strings. Converting to binary strings guarantees exact binary matching.
                $qb->andWhere('l.id IN (:listIds)')
                    ->setParameter('listIds', array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $validUuids));
            }
        }

        /** @var list<Poi> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
