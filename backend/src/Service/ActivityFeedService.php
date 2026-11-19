<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivityEvent;
use App\Entity\Poi;
use App\Entity\User;
use App\Repository\ActivityEventRepository;
use App\Repository\ReactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Centralizes activity feed event recording, visibility synchronization, target cleanup, and feed assembly.
 */
final class ActivityFeedService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityEventRepository $events,
        private readonly ReactionRepository $reactions,
    ) {
    }

    /**
     * Persists a new activity event into the current unit of work (caller flushes).
     *
     * @param User                 $actor      Authenticated user performing the action
     * @param string               $eventType  One of ActivityEvent::EVENT_*
     * @param Poi                  $poi        Parent POI
     * @param string               $targetType One of ActivityEvent::TARGET_*
     * @param Uuid                 $targetId   UUID of the target entity
     * @param bool                 $isPublic   Target's public visibility flag
     * @param array<string, mixed> $payload    Optional structured metadata
     */
    public function recordEvent(
        User $actor,
        string $eventType,
        Poi $poi,
        string $targetType,
        Uuid $targetId,
        bool $isPublic = false,
        array $payload = [],
    ): ActivityEvent {
        $event = new ActivityEvent($actor, $eventType, $poi, $targetType, $targetId, $isPublic, $payload);
        $this->em->persist($event);

        return $event;
    }

    /**
     * Synchronizes the denormalized isPublic flag on all feed events for a target entity.
     */
    public function syncVisibility(string $targetType, Uuid $targetId, bool $isPublic): void
    {
        $this->events->updateVisibilityByTarget($targetType, $targetId, $isPublic);
    }

    /**
     * Removes all feed events referencing a deleted or withdrawn target entity.
     */
    public function deleteForTarget(string $targetType, Uuid $targetId): void
    {
        $this->events->deleteByTarget($targetType, $targetId);
    }

    /**
     * Builds a cursor-paginated activity feed page formatted to the OpenAPI ActivityEvent schema.
     *
     * @param User|null                                       $viewer Authenticated user, or null for guests
     * @param int                                             $limit  Maximum items per page (1..100)
     * @param array{createdAt: \DateTimeImmutable, id: Uuid}|null $cursor Decoded keyset cursor
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function getFeed(?User $viewer, int $limit, ?array $cursor): array
    {
        $rows = $this->events->findFeedPage(
            $viewer,
            $limit,
            $cursor['createdAt'] ?? null,
            $cursor['id'] ?? null,
        );

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Batch-load POI-level reactions in a single query to prevent N+1 queries.
        $poiIdsByKey = [];
        foreach ($rows as $event) {
            $poiId = $event->getPoi()->getId();
            $poiIdsByKey[$poiId->toRfc4122()] = $poiId;
        }
        $reactionsByPoi = $this->reactions->getAggregatedForPois(array_values($poiIdsByKey), $viewer);

        $items = [];
        foreach ($rows as $event) {
            $poi = $event->getPoi();
            $poiKey = $poi->getId()->toRfc4122();

            $items[] = [
                'id' => $event->getId()->toRfc4122(),
                'eventType' => $event->getEventType(),
                'actorName' => $event->getActor()->getDisplayName(),
                'poiId' => $poiKey,
                'poiName' => $poi->getName(),
                'isPublic' => $event->isPublic(),
                'createdAt' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'reactions' => $reactionsByPoi[$poiKey] ?? [],
            ];
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = self::encodeCursor($last->getCreatedAt(), $last->getId());
        }

        return [
            'items' => $items,
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * Encodes a (createdAt, id) keyset cursor into an opaque URL-safe base64 string.
     */
    public static function encodeCursor(\DateTimeImmutable $createdAt, Uuid $id): string
    {
        $raw = $createdAt->format('U') . '|' . $id->toRfc4122();

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Decodes a URL-safe base64 keyset cursor into its (createdAt, id) components, or null if invalid.
     *
     * @return array{createdAt: \DateTimeImmutable, id: Uuid}|null
     */
    public static function decodeCursor(string $cursor): ?array
    {
        $padded = strtr($cursor, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false || !str_contains($decoded, '|')) {
            return null;
        }

        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !Uuid::isValid($parts[1])) {
            return null;
        }

        $createdAt = \DateTimeImmutable::createFromFormat('U', $parts[0], new \DateTimeZone('UTC'));
        if ($createdAt === false) {
            return null;
        }

        return [
            'createdAt' => $createdAt,
            'id' => Uuid::fromString($parts[1]),
        ];
    }
}
