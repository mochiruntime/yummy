<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an uploaded, EXIF-stripped photo attached to a POI (and optionally to a specific Review).
 *
 * Security & Media Privacy:
 * - All EXIF/GPS/IPTC metadata is stripped server-side prior to disk storage.
 * - Image files are stored outside the public web root and streamed via GET /api/photos/{id}/file.
 * - Parent-child visibility: Guests only see photos where poi.isPublic=true, photo.isPublic=true,
 *   AND (if review != null) review.isPublic=true.
 */
#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'photos')]
#[ORM\Index(name: 'idx_photos_poi_created', columns: ['poi_id', 'created_at'])]
#[ORM\Index(name: 'idx_photos_review', columns: ['review_id'])]
#[ORM\Index(name: 'idx_photos_uploader', columns: ['uploader_id'])]
#[ORM\Index(name: 'idx_photos_is_public', columns: ['is_public'])]
class Photo
{
    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['photo:read', 'poi:read'])]
    private Uuid $id;

    /**
     * Parent POI this photo belongs to. Deleted via CASCADE if the POI is deleted.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * Optional review this photo is attached to (nullable).
     * Set to NULL via ON DELETE SET NULL if the review is deleted, keeping the photo in the POI gallery.
     */
    #[ORM\ManyToOne(targetEntity: Review::class)]
    #[ORM\JoinColumn(name: 'review_id', nullable: true, onDelete: 'SET NULL')]
    private ?Review $review;

    /**
     * Member who uploaded the photo. Deleted via CASCADE if the uploader is removed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploader_id', nullable: false, onDelete: 'CASCADE')]
    private User $uploader;

    /**
     * Random hexadecimal storage key (VARCHAR(64)) used to locate full and thumbnail files on disk.
     */
    #[ORM\Column(name: 'storage_key', length: 64)]
    private string $storageKey;

    /**
     * Sanitized MIME type of the processed image (e.g., "image/jpeg", "image/png", "image/webp").
     */
    #[ORM\Column(name: 'mime_type', length: 32)]
    private string $mimeType;

    /**
     * Pixel width of the processed full-size variant (INT UNSIGNED).
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $width;

    /**
     * Pixel height of the processed full-size variant (INT UNSIGNED).
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $height;

    /**
     * Optional short caption describing the dish or scene (VARCHAR(255), nullable).
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['photo:read', 'poi:read'])]
    private ?string $caption = null;

    /**
     * Granular visibility flag (TINYINT(1), default false).
     * Controls whether guests can view/download this photo when parent POI (and review, if any) are public.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    #[Groups(['photo:read', 'poi:read'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    /**
     * Timestamp when the photo was uploaded and processed.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['photo:read', 'poi:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new Photo entity.
     *
     * @param Poi         $poi        Parent POI
     * @param User        $uploader   Authenticated uploader
     * @param string      $storageKey Random disk storage identifier
     * @param string      $mimeType   Sanitized MIME type
     * @param int         $width      Full variant width in pixels
     * @param int         $height     Full variant height in pixels
     * @param Review|null $review     Optional attached review
     * @param string|null $caption    Optional caption
     * @param bool        $isPublic   Whether visible to guests when parents are public
     */
    public function __construct(
        Poi $poi,
        User $uploader,
        string $storageKey,
        string $mimeType,
        int $width,
        int $height,
        ?Review $review = null,
        ?string $caption = null,
        bool $isPublic = false,
    ) {
        $this->id = Uuid::v7();
        $this->poi = $poi;
        $this->uploader = $uploader;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->width = $width;
        $this->height = $height;
        $this->review = $review;
        $this->caption = $caption !== null && trim($caption) !== '' ? trim($caption) : null;
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
     * Returns the attached Review entity, or null if uploaded directly to the POI.
     */
    public function getReview(): ?Review
    {
        return $this->review;
    }

    /**
     * Returns the RFC 4122 UUID string of the attached review for API serialization.
     */
    #[Groups(['photo:read', 'poi:read'])]
    #[SerializedName('reviewId')]
    public function getReviewId(): ?string
    {
        return $this->review?->getId()->toRfc4122();
    }

    /**
     * Returns the uploader User entity.
     */
    public function getUploader(): User
    {
        return $this->uploader;
    }

    /**
     * Returns the disk storage key.
     */
    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    /**
     * Returns the sanitized MIME type.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Returns the full-size image width in pixels.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Returns the full-size image height in pixels.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Returns the optional caption.
     */
    public function getCaption(): ?string
    {
        return $this->caption;
    }

    /**
     * Returns true if this photo is marked public.
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
     * Returns the relative API URL to stream the full-size EXIF-stripped image.
     */
    #[Groups(['photo:read', 'poi:read'])]
    public function getUrl(): string
    {
        return sprintf('/api/photos/%s/file?size=full', $this->id->toRfc4122());
    }

    /**
     * Returns the relative API URL to stream the 400px thumbnail variant.
     */
    #[Groups(['photo:read', 'poi:read'])]
    #[SerializedName('thumbUrl')]
    public function getThumbUrl(): string
    {
        return sprintf('/api/photos/%s/file?size=thumb', $this->id->toRfc4122());
    }

    /**
     * Returns the upload timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns aggregated emoji reactions (populated by PoiDetailAssembler; empty on direct creation).
     *
     * @return list<array{emoji: string, count: int, reactedByMe: bool}>
     */
    #[Groups(['photo:read', 'poi:read'])]
    public function getReactions(): array
    {
        return [];
    }
}
