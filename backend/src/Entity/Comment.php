<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a lightweight discussion comment on a Point of Interest (POI).
 *
 * Privacy:
 * - Defaults to isPublic = false.
 * - Unauthenticated guests only see comments where both comment.isPublic and poi.isPublic are true.
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'idx_comments_poi_created', columns: ['poi_id', 'created_at'])]
#[ORM\Index(name: 'idx_comments_author', columns: ['author_id'])]
#[ORM\Index(name: 'idx_comments_is_public', columns: ['is_public'])]
class Comment
{
    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['comment:read', 'poi:read'])]
    private Uuid $id;

    /**
     * Target POI being discussed. Deleted via CASCADE if the parent POI is removed.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * Member who posted this comment. Deleted via CASCADE if the author account is removed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    /**
     * Text content of the comment (LONGTEXT, non-nullable).
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['comment:read', 'poi:read'])]
    private string $body;

    /**
     * Granular visibility flag (TINYINT(1), default false).
     * When false, visible only to authenticated members. When true, visible to guests if parent POI is public.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    #[Groups(['comment:read', 'poi:read'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    /**
     * Timestamp when the comment was posted.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['comment:read', 'poi:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new Comment entity.
     *
     * @param Poi    $poi      Target POI
     * @param User   $author   Authenticated author
     * @param string $body     Non-empty comment text
     * @param bool   $isPublic Whether visible to guests when parent POI is public
     */
    public function __construct(
        Poi $poi,
        User $author,
        string $body,
        bool $isPublic = false,
    ) {
        $this->id = Uuid::v7();
        $this->poi = $poi;
        $this->author = $author;
        $this->body = trim($body);
        $this->isPublic = $isPublic;
        $this->createdAt = new \DateTimeImmutable();
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
    #[Groups(['comment:read', 'poi:read'])]
    #[SerializedName('authorName')]
    public function getAuthorName(): string
    {
        return $this->author->getDisplayName();
    }

    /**
     * Returns the comment text body.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Returns true if this comment is marked public.
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Updates the public visibility flag.
     */
    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
