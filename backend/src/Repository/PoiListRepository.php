<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PoiList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Doctrine repository for querying and managing curated POI lists / categories.
 *
 * Implements user-scoped unique slug generation to avoid URL collisions between different users.
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

    /**
     * Generates a URL-safe slug guaranteed to be unique for the given list owner.
     *
     * Slugs are scoped per owner rather than globally. If user A already has "tokyo-ramen",
     * user B can still create "tokyo-ramen". If user A creates a second list called "Tokyo Ramen",
     * this method automatically suffixes an incrementing index: "tokyo-ramen-2", "tokyo-ramen-3", etc.
     *
     * @param User   $owner The owner user who will own the list
     * @param string $name  The human-readable list name to slugify
     */
    public function generateUniqueSlug(User $owner, string $name): string
    {
        $slugger = new AsciiSlugger();
        $baseSlug = mb_strtolower((string) $slugger->slug($name));

        // Fallback for names containing only non-ASCII characters that slugify to empty strings
        if ($baseSlug === '') {
            $baseSlug = 'list';
        }

        $slug = $baseSlug;
        $counter = 1;

        // Loop until an unused (owner, slug) tuple is found
        while ($this->findOneBy(['owner' => $owner, 'slug' => $slug]) !== null) {
            ++$counter;
            $slug = sprintf('%s-%d', $baseSlug, $counter);
        }

        return $slug;
    }
}
