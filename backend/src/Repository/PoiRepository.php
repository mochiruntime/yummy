<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Poi;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
     * Finds POIs matching optional bounding-box coordinates, list filters, creator scope, search query, and pagination.
     *
     * @param array{minLng: float, minLat: float, maxLng: float, maxLat: float}|null $bbox        Viewport bounding box
     * @param list<string>                                                           $listIds     Category/list filter IDs
     * @param bool                                                                   $isGuest     Whether the viewer is unauthenticated
     * @param User|null                                                              $mineAuthor  When set, restricts to POIs created by this user
     * @param string|null                                                            $searchQuery Optional case-insensitive name/address filter
     * @param int|null                                                               $limit       Optional page size (uses Doctrine Paginator for to-many join safety)
     * @param int                                                                    $offset      Zero-based page offset when $limit is set
     *
     * @return list<Poi>
     */
    public function findByFilters(
        ?array $bbox,
        array $listIds,
        bool $isGuest,
        ?User $mineAuthor = null,
        ?string $searchQuery = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if ($isGuest) {
            // Guests only see public lists attached to public POIs and cannot filter by private list IDs.
            $qb->leftJoin('p.lists', 'l', 'WITH', 'l.isPublic = true')
                ->addSelect('l')
                ->andWhere('p.isPublic = true');
        } else {
            $qb->leftJoin('p.lists', 'l')
                ->addSelect('l');
        }

        if ($mineAuthor !== null) {
            $qb->andWhere('p.createdBy = :mineAuthor')
                ->setParameter('mineAuthor', $mineAuthor->getId()->toBinary());
        }

        if ($searchQuery !== null && $searchQuery !== '') {
            // Escape LIKE wildcards using '!' so literal '%' or '_' characters in user queries do not broaden matches.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($searchQuery));
            $qb->andWhere('(LOWER(p.name) LIKE :searchPattern ESCAPE \'!\' OR LOWER(COALESCE(p.address, \'\')) LIKE :searchPattern ESCAPE \'!\')')
                ->setParameter('searchPattern', '%'.$escaped.'%');
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

        $dqlQuery = $qb->getQuery();

        if ($limit !== null) {
            $dqlQuery->setFirstResult($offset)->setMaxResults($limit);
            // fetchJoinCollection: true paginates distinct Poi roots rather than joined (poi, list) rows.
            /** @var Paginator<Poi> $paginator */
            $paginator = new Paginator($dqlQuery, fetchJoinCollection: true);

            return array_values(iterator_to_array($paginator));
        }

        /** @var list<Poi> $results */
        $results = $dqlQuery->getResult();

        return $results;
    }
}
