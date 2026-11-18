<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a single emoji reaction toggled by a user on a POI, Review, or Photo.
 *
 * Uniqueness & Aggregation:
 * - A unique constraint on (target_type, target_id, user_id, emoji) ensures each user can toggle a given emoji once per target.
 * - API responses aggregate reactions by emoji ({ emoji, count, reactedByMe }).
 */
#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\Table(name: 'reactions')]
#[ORM\Index(name: 'idx_reactions_poi', columns: ['poi_id'])]
#[ORM\Index(name: 'idx_reactions_review', columns: ['review_id'])]
#[ORM\Index(name: 'idx_reactions_photo', columns: ['photo_id'])]
#[ORM\Index(name: 'idx_reactions_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_reactions_target_user_emoji', columns: ['target_type', 'target_id', 'user_id', 'emoji'])]
class Reaction
{
    public const TARGET_POI = 'poi';
    public const TARGET_REVIEW = 'review';
    public const TARGET_PHOTO = 'photo';

    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Parent POI owning the reaction or owning the reacted review/photo.
     * Stored as BINARY(16) non-nullable FK; deleted via CASCADE if the parent POI is removed.
     * Visibility is governed by both the POI's and the reaction's isPublic flags.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * Optional target review when targetType is 'review'.
     * Stored as nullable BINARY(16) FK; deleted via CASCADE if the review is removed.
     * Null when reacting directly to a POI or photo; guest visibility requires review.isPublic = true.
     */
    #[ORM\ManyToOne(targetEntity: Review::class)]
    #[ORM\JoinColumn(name: 'review_id', nullable: true, onDelete: 'CASCADE')]
    private ?Review $review = null;

    /**
     * Optional target photo when targetType is 'photo'.
     * Stored as nullable BINARY(16) FK; deleted via CASCADE if the photo is removed.
     * Null when reacting directly to a POI or review; guest visibility requires photo.isPublic = true.
     */
    #[ORM\ManyToOne(targetEntity: Photo::class)]
    #[ORM\JoinColumn(name: 'photo_id', nullable: true, onDelete: 'CASCADE')]
    private ?Photo $photo = null;

    /**
     * Discriminator identifying the reacted target ('poi', 'review', or 'photo').
     * Stored as VARCHAR(16), non-nullable; used with targetId in the uniqueness constraint
     * because MariaDB allows duplicate rows across nullable foreign key columns.
     * No independent privacy implication beyond the referenced target.
     */
    #[ORM\Column(name: 'target_type', length: 16)]
    private string $targetType;

    /**
     * UUID of the reacted target entity (POI, Review, or Photo).
     * Stored as BINARY(16) non-nullable; paired with targetType for polymorphic uniqueness.
     * No independent privacy implication beyond the referenced target.
     */
    #[ORM\Column(name: 'target_id', type: 'uuid')]
    private Uuid $targetId;

    /**
     * Authenticated member who toggled this reaction. Deleted via CASCADE if the user is removed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * UTF-8 emoji string or shortcode (VARCHAR(16), non-nullable).
     */
    #[ORM\Column(length: 16)]
    private string $emoji;

    /**
     * Granular visibility flag (TINYINT(1), default false).
     * Guests only count reactions where both reaction.isPublic and poi.isPublic are true.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    private bool $isPublic = false;

    /**
     * Timestamp when the reaction was added.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new Reaction entity targeting a POI, Review, or Photo.
     *
     * @param Poi         $poi      Parent POI
     * @param User        $user     Reacting member
     * @param string      $emoji    Emoji character(s) (max 16 chars)
     * @param bool        $isPublic Whether visible to guests when parent target/POI are public
     * @param Review|null $review   Target review (mutually exclusive with $photo)
     * @param Photo|null  $photo    Target photo (mutually exclusive with $review)
     */
    public function __construct(
        Poi $poi,
        User $user,
        string $emoji,
        bool $isPublic = false,
        ?Review $review = null,
        ?Photo $photo = null,
    ) {
        if ($review !== null && $photo !== null) {
            throw new \InvalidArgumentException('A reaction cannot target both a review and a photo.');
        }

        $this->id = Uuid::v7();
        $this->poi = $poi;
        $this->review = $review;
        $this->photo = $photo;
        $this->user = $user;
        $this->emoji = trim($emoji);
        $this->isPublic = $isPublic;
        $this->createdAt = new \DateTimeImmutable();

        // Derive non-nullable discriminator + target UUID for MariaDB unique index enforcement.
        if ($review !== null) {
            $this->targetType = self::TARGET_REVIEW;
            $this->targetId = $review->getId();
        } elseif ($photo !== null) {
            $this->targetType = self::TARGET_PHOTO;
            $this->targetId = $photo->getId();
        } else {
            $this->targetType = self::TARGET_POI;
            $this->targetId = $poi->getId();
        }
    }

    /**
     * Returns the UUIDv7 primary key.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the target POI.
     */
    public function getPoi(): Poi
    {
        return $this->poi;
    }

    /**
     * Returns the target review, or null if not a review reaction.
     */
    public function getReview(): ?Review
    {
        return $this->review;
    }

    /**
     * Returns the target photo, or null if not a photo reaction.
     */
    public function getPhoto(): ?Photo
    {
        return $this->photo;
    }

    /**
     * Returns the target type discriminator ('poi', 'review', or 'photo').
     */
    public function getTargetType(): string
    {
        return $this->targetType;
    }

    /**
     * Returns the UUID of the reacted target.
     */
    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    /**
     * Returns the user who reacted.
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Returns the emoji string.
     */
    public function getEmoji(): string
    {
        return $this->emoji;
    }

    /**
     * Returns true if this reaction is included in public guest aggregates.
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
