<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Poi;
use App\Entity\PoiRevision;
use App\Entity\User;
use App\Repository\PoiRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages wiki-style versioning and snapshot auditing for POIs.
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
     * @param array<string, mixed> $changedFields
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

        $this->em->flush();

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
     * Generates a full snapshot representation of a POI's state.
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
