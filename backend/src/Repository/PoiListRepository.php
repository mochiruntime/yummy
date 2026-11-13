<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PoiList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * @extends ServiceEntityRepository<PoiList>
 */
class PoiListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoiList::class);
    }

    /**
     * @return list<PoiList>
     */
    public function findAllOrderedByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Finds a unique slug for a given user's list.
     * E.g., "trip-to-portugal" -> "trip-to-portugal-2" if already taken by that user.
     */
    public function generateUniqueSlug(User $owner, string $name): string
    {
        $slugger = new AsciiSlugger();
        $baseSlug = mb_strtolower((string) $slugger->slug($name));
        if ($baseSlug === '') {
            $baseSlug = 'list';
        }

        $slug = $baseSlug;
        $counter = 1;

        while ($this->findOneBy(['owner' => $owner, 'slug' => $slug]) !== null) {
            ++$counter;
            $slug = sprintf('%s-%d', $baseSlug, $counter);
        }

        return $slug;
    }
}
