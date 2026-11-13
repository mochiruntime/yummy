<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poi;
use App\Entity\PoiRevision;
use App\Entity\User;
use App\Repository\PoiRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service managing wiki-style versioning, state snapshots, and audit history for POIs.
 *
 * Core architectural principles:
 * - Immutable Revision History: Edits never overwrite past revisions. Every update saves a state snapshot.
 * - Forward Reversion Pattern: Reverting to a previous revision creates a new revision N+1 that restores
 *   the historical attributes rather than deleting subsequent history, preserving complete audit lineage.
 */
final class PoiVersioningService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PoiRevisionRepository $revisions,
    ) {
    }

    /**
     * Records an immutable revision entry capturing updated fields.
     *
     * @param Poi                  $poi           Target POI being modified
     * @param User                 $editor        User who made the changes
     * @param array<string, mixed> $changedFields Dictionary of modified fields and state snapshots
     */
    public function recordRevision(Poi $poi, User $editor, array $changedFields): PoiRevision
    {
        $revisionNumber = $poi->getVersion();
        $revision = new PoiRevision($poi, $editor, $revisionNumber, $changedFields);

        $this->em->persist($revision);

        return $revision;
    }

    /**
     * Reverts a POI's attributes to match a prior revision, creating a new forward revision.
     *
     * @param Poi  $poi            Target POI to restore
     * @param int  $revisionNumber Historical revision number to restore from
     * @param User $editor         User performing the restoration
     *
     * @return PoiRevision|null The newly created forward revision, or null if the target revision does not exist
     */
    public function revertToRevision(Poi $poi, int $revisionNumber, User $editor): ?PoiRevision
    {
        $target = $this->revisions->findOneByPoiAndRevision($poi, $revisionNumber);
        if ($target === null) {
            return null;
        }

        $snapshot = $target->getChangedFields()['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }

        // Restore historical attributes from the snapshot
        if (isset($snapshot['name']) && is_string($snapshot['name'])) {
            $poi->setName($snapshot['name']);
        }
        if (array_key_exists('description', $snapshot)) {
            $poi->setDescription(is_string($snapshot['description']) ? $snapshot['description'] : null);
        }
        if (array_key_exists('address', $snapshot)) {
            $poi->setAddress(is_string($snapshot['address']) ? $snapshot['address'] : null);
        }
        if (isset($snapshot['latitude'], $snapshot['longitude']) && is_numeric($snapshot['latitude']) && is_numeric($snapshot['longitude'])) {
            $poi->setCoordinates((float) $snapshot['latitude'], (float) $snapshot['longitude']);
        }

        // Flush POI attribute changes first so Doctrine increments the #[ORM\Version] lock counter.
        // This ensures the forward revision record gets the newly incremented version number.
        $this->em->flush();

        // Forward revert: record a new revision that captures what was restored and from which past revision
        $forwardDiff = [
            'revertedFromRevision' => $revisionNumber,
            'snapshot' => $snapshot,
        ];

        $forwardRevision = new PoiRevision($poi, $editor, $poi->getVersion(), $forwardDiff);
        $this->em->persist($forwardRevision);
        $this->em->flush();

        return $forwardRevision;
    }

    /**
     * Generates a full snapshot dictionary of a POI's editable attributes.
     *
     * @param Poi $poi Target POI
     *
     * @return array<string, mixed>
     */
    public function buildSnapshot(Poi $poi): array
    {
        return [
            'name' => $poi->getName(),
            'description' => $poi->getDescription(),
            'address' => $poi->getAddress(),
            'latitude' => $poi->getLatitude(),
            'longitude' => $poi->getLongitude(),
        ];
    }
}
