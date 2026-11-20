<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable-order audit entry representing a user action in the chronological activity feed.
 *
 * Visibility Invariant:
 * - Denormalizes the target item's isPublic flag, synchronized on visibility toggles.
 * - Unauthenticated guests only see events where both event.isPublic and poi.isPublic are true
 *   (and parent review/photo visibility holds for review-attached photos or reactions).
 */
#[ORM\Entity(repositoryClass: ActivityEventRepository::class)]
#[ORM\Table(name: 'activity_events')]
#[ORM\Index(name: 'idx_activity_events_created_id', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_activity_events_poi', columns: ['poi_id'])]
#[ORM\Index(name: 'idx_activity_events_target', columns: ['target_type', 'target_id'])]
#[ORM\Index(name: 'idx_activity_events_actor', columns: ['actor_id'])]
class ActivityEvent
{
    public const EVENT_POI_CREATED = 'poi.created';
    public const EVENT_POI_EDITED = 'poi.edited';
    public const EVENT_REVIEW_ADDED = 'review.added';
    public const EVENT_COMMENT_ADDED = 'comment.added';
    public const EVENT_PHOTO_ADDED = 'photo.added';
    public const EVENT_REACTION_ADDED = 'reaction.added';

    public const TARGET_POI = 'poi';
    public const TARGET_REVIEW = 'review';
    public const TARGET_COMMENT = 'comment';
    public const TARGET_PHOTO = 'photo';
    public const TARGET_REACTION = 'reaction';

    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     * Non-nullable primary key; used alongside createdAt as deterministic keyset pagination tie-breaker.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Authenticated member who performed the recorded action.
     * Stored as BINARY(16) non-nullable FK to users(id) with ON DELETE CASCADE.
     * Display name is exposed in feed responses for items visible to the viewer.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: false, onDelete: 'CASCADE')]
    private User $actor;

    /**
     * Event discriminator ('poi.created', 'poi.edited', 'review.added', 'comment.added', 'photo.added', 'reaction.added').
     * Stored as VARCHAR(32), non-nullable; no default value.
     */
    #[ORM\Column(name: 'event_type', length: 32)]
    private string $eventType;

    /**
     * Parent POI associated with this event.
     * Stored as BINARY(16) non-nullable FK to pois(id) with ON DELETE CASCADE.
     * Guests can never see events whose parent POI has isPublic = false.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * Polymorphic target entity type ('poi', 'review', 'comment', 'photo', or 'reaction').
     * Stored as VARCHAR(32), non-nullable; used to synchronize visibility and clean up deleted targets.
     */
    #[ORM\Column(name: 'target_type', length: 32)]
    private string $targetType;

    /**
     * UUID of the target entity referenced by targetType.
     * Stored as BINARY(16), non-nullable; paired with targetType for visibility sync and cleanup.
     */
    #[ORM\Column(name: 'target_id', type: 'uuid')]
    private Uuid $targetId;

    /**
     * Structured JSON metadata for the event (e.g., changedFields, revertedToRevision, emoji, targetType).
     * Stored as MariaDB JSON column, non-nullable, defaulting to an empty object/array.
     * Must never contain private entity content that would bypass visibility checks.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /**
     * Denormalized visibility flag matching the target item's public status (TINYINT(1), default false).
     * Synchronized when the target's visibility is toggled; guests require both this and poi.isPublic to be true.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    private bool $isPublic = false;

    /**
     * UTC timestamp when the activity event occurred.
     * Stored as DATETIME (datetime_immutable), non-nullable; indexed with id for cursor pagination.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new ActivityEvent record.
     *
     * @param User                 $actor      Authenticated user who triggered the action
     * @param string               $eventType  One of the EVENT_* constants
     * @param Poi                  $poi        Parent POI
     * @param string               $targetType One of the TARGET_* constants
     * @param Uuid                 $targetId   UUID of the target entity
     * @param bool                 $isPublic   Initial public visibility flag
     * @param array<string, mixed> $payload    Optional structured metadata
     */
    public function __construct(
        User $actor,
        string $eventType,
        Poi $poi,
        string $targetType,
        Uuid $targetId,
        bool $isPublic = false,
        array $payload = [],
    ) {
        $this->id = Uuid::v7();
        $this->actor = $actor;
        $this->eventType = $eventType;
        $this->poi = $poi;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->isPublic = $isPublic;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Returns the event's UUIDv7 identifier.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the member who performed the action.
     */
    public function getActor(): User
    {
        return $this->actor;
    }

    /**
     * Returns the event type discriminator (ActivityEvent::EVENT_*).
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * Returns the parent POI associated with this event.
     */
    public function getPoi(): Poi
    {
        return $this->poi;
    }

    /**
     * Returns the polymorphic target entity type (ActivityEvent::TARGET_*).
     */
    public function getTargetType(): string
    {
        return $this->targetType;
    }

    /**
     * Returns the UUID of the target entity referenced by targetType.
     */
    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    /**
     * Returns structured metadata associated with this event.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Returns whether this event is visible to unauthenticated guests (subject to parent visibility).
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Updates the denormalized public visibility flag for this event.
     */
    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    /**
     * Returns the UTC timestamp when this event was recorded.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
