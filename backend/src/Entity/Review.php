<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a user's culinary review and numeric rating (1-5) for a Point of Interest (POI).
 *
 * Multiple Reviews & Privacy:
 * - A user may author multiple reviews on the same POI over time (e.g., repeat visits).
 * - Defaults to isPublic = false; guests only see reviews where both review.isPublic and poi.isPublic are true.
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews')]
#[ORM\Index(name: 'idx_reviews_poi_created', columns: ['poi_id', 'created_at'])]
#[ORM\Index(name: 'idx_reviews_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_reviews_is_public', columns: ['is_public'])]
class Review
{
    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['review:read', 'poi:read'])]
    private Uuid $id;

    /**
     * Target POI being reviewed. Deleted via CASCADE if the parent POI is removed.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * Member who authored this review. Deleted via CASCADE if the author account is removed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Star rating score from 1 (lowest) to 5 (highest), stored as SMALLINT.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Groups(['review:read', 'poi:read'])]
    private int $rating;

    /**
     * Optional written review commentary, dish notes, or tasting impressions (LONGTEXT, nullable).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['review:read', 'poi:read'])]
    private ?string $body = null;

    /**
     * Granular visibility flag (TINYINT(1), default false).
     * When false, visible only to authenticated instance members. When true, visible to guests if parent POI is public.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    #[Groups(['review:read', 'poi:read'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    /**
     * Timestamp when the review was originally posted.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['review:read', 'poi:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Timestamp of the most recent update to this review (e.g., visibility toggle).
     */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a new Review entity.
     *
     * @param Poi         $poi      Target POI
     * @param User        $author   Authenticated author
     * @param int         $rating   Numeric rating between 1 and 5
     * @param string|null $body     Optional written review text
     * @param bool        $isPublic Whether the review is publicly visible when the parent POI is public
     */
    public function __construct(
        Poi $poi,
        User $author,
        int $rating,
        ?string $body = null,
        bool $isPublic = false,
    ) {
        $this->id = Uuid::v7();
        $this->poi = $poi;
        $this->author = $author;
        $this->rating = $rating;
        $this->body = $body !== null && trim($body) !== '' ? trim($body) : null;
        $this->isPublic = $isPublic;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Returns the UUIDv7 primary key.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the parent POI entity.
     */
    public function getPoi(): Poi
    {
        return $this->poi;
    }

    /**
     * Returns the author User entity.
     */
    public function getAuthor(): User
    {
        return $this->author;
    }

    /**
     * Returns the author's public display name for API serialization.
     */
    #[Groups(['review:read', 'poi:read'])]
    #[SerializedName('authorName')]
    public function getAuthorName(): string
    {
        return $this->author->getDisplayName();
    }

    /**
     * Returns the numeric star rating (1-5).
     */
    public function getRating(): int
    {
        return $this->rating;
    }

    /**
     * Returns the optional written review body.
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Returns true if this review is marked public.
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Updates the public visibility flag and refreshes updatedAt.
     */
    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last update timestamp.
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns aggregated emoji reactions (populated by PoiDetailAssembler; empty on direct creation).
     *
     * @return list<array{emoji: string, count: int, reactedByMe: bool}>
     */
    #[Groups(['review:read', 'poi:read'])]
    public function getReactions(): array
    {
        return [];
    }
}
