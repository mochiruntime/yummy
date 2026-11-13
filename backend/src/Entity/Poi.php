<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PoiRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a Point of Interest (POI) on the map (e.g., restaurant, bakery, street food stall).
 *
 * Core architectural features:
 * - High-precision Geospatial Coordinates: Decimal latitude and longitude stored as DECIMAL(10,7)
 *   for human/API portability, and synchronised with an internal MariaDB spatial POINT (SRID 4326)
 *   backed by a SPATIAL index for fast MBR bounding-box viewport queries.
 * - Concurrency Control: Optimistic locking via #[ORM\Version] ensures concurrent edits detect collisions.
 * - Granular Privacy: Private by default; public POIs are discoverable on the homepage map by guests.
 * - Collaborative Lists: Many-to-Many association with user-curated lists/categories.
 */
#[ORM\Entity(repositoryClass: PoiRepository::class)]
#[ORM\Table(name: 'pois')]
#[ORM\Index(name: 'idx_pois_location', columns: ['location'], flags: ['spatial'])]
class Poi
{
    /**
     * Unique identifier using time-ordered UUIDv7 for sequential index locality.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['poi:read', 'poi:summary'])]
    private Uuid $id;

    /**
     * User who authored and initially submitted this POI.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    /**
     * Venue name (e.g., "Pizzeria Da Michele"). Max 255 characters.
     */
    #[ORM\Column(length: 255)]
    #[Groups(['poi:read', 'poi:summary'])]
    private string $name;

    /**
     * Optional editorial notes, culinary highlights, tips, or ordering recommendations.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['poi:read'])]
    private ?string $description = null;

    /**
     * Physical street address or reverse-geocoded location description. Max 500 characters.
     */
    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['poi:read', 'poi:summary'])]
    private ?string $address = null;

    /**
     * WGS 84 latitude in degrees (-90.0 to +90.0).
     * Stored as DECIMAL(10,7) string to preserve precision without float rounding errors.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $latitude;

    /**
     * WGS 84 longitude in degrees (-180.0 to +180.0).
     * Stored as DECIMAL(10,7) string to preserve precision without float rounding errors.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $longitude;

    /**
     * Native MariaDB 25-byte packed Well-Known Binary (WKB) POINT geometry with SRID 4326.
     * Backs the SPATIAL INDEX for fast bounding-box queries (MBRContains).
     */
    #[ORM\Column(type: 'string', columnDefinition: 'POINT NOT NULL /*!80003 SRID 4326 */')]
    private string $location;

    /**
     * Visibility flag. False = private to instance members; True = public to all visitors.
     */
    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    #[Groups(['poi:read', 'poi:summary'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    /**
     * Optimistic locking counter. Managed automatically by Doctrine ORM during flush.
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\Version]
    #[Groups(['poi:read', 'poi:summary'])]
    private int $version = 1;

    /**
     * Curated lists and tags containing this POI.
     *
     * @var Collection<int, PoiList>
     */
    #[ORM\ManyToMany(targetEntity: PoiList::class, inversedBy: 'pois')]
    #[ORM\JoinTable(name: 'poi_lists')]
    #[ORM\JoinColumn(name: 'poi_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'list_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['poi:read', 'poi:summary'])]
    private Collection $lists;

    /**
     * Timestamp when the POI was initially dropped.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Timestamp of the most recent attribute update or revision.
     */
    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:read'])]
    #[SerializedName('updatedAt')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Creates a new POI instance.
     *
     * @param User        $createdBy   User authoring the POI
     * @param string      $name        Venue display name
     * @param float       $latitude    WGS 84 latitude (-90 to +90)
     * @param float       $longitude   WGS 84 longitude (-180 to +180)
     * @param string|null $description Optional descriptive notes
     * @param string|null $address     Optional physical street address
     * @param bool        $isPublic    Whether visible to unauthenticated visitors
     */
    public function __construct(
        User $createdBy,
        string $name,
        float $latitude,
        float $longitude,
        ?string $description = null,
        ?string $address = null,
        bool $isPublic = false,
    ) {
        $this->id = Uuid::v7();
        $this->createdBy = $createdBy;
        $this->name = trim($name);
        $this->description = $description !== null ? trim($description) : null;
        $this->address = $address !== null ? trim($address) : null;
        $this->latitude = sprintf('%.7f', $latitude);
        $this->longitude = sprintf('%.7f', $longitude);
        $this->location = self::buildPackedPoint($latitude, $longitude);
        $this->isPublic = $isPublic;
        $this->lists = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Constructs the 25-byte packed Well-Known Binary (WKB) representation for MariaDB POINT SRID 4326.
     *
     * MariaDB requires spatial data bound to prepared statements to follow the MySQL/MariaDB internal
     * GIS format: a 4-byte little-endian SRID integer header followed by standard OGC WKB geometry.
     *
     * Byte breakdown (25 bytes total):
     * - Bytes 0..3: SRID 4326 as 32-bit unsigned integer, little-endian: pack('V', 4326)
     * - Byte 4:     Byte order indicator (0x01 = Little-endian / NDR): pack('C', 1)
     * - Bytes 5..8: WKB geometry type (1 = WKBPoint) as 32-bit uint, little-endian: pack('V', 1)
     * - Bytes 9..16: X coordinate (Longitude) as 64-bit IEEE 754 double, little-endian: pack('e', $lng)
     * - Bytes 17..24: Y coordinate (Latitude) as 64-bit IEEE 754 double, little-endian: pack('e', $lat)
     */
    public static function buildPackedPoint(float $latitude, float $longitude): string
    {
        return pack('V', 4326)
            .pack('C', 1)
            .pack('V', 1)
            .pack('e', $longitude)
            .pack('e', $latitude);
    }

    /**
     * Returns the UUIDv7 primary key.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the user who created this POI.
     */
    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    /**
     * Returns a lightweight summary of the creator for API serialization.
     *
     * @return array{id: string, displayName: string}
     */
    #[Groups(['poi:read'])]
    #[SerializedName('createdBy')]
    public function getCreatedBySummary(): array
    {
        return [
            'id' => $this->createdBy->getId()->toRfc4122(),
            'displayName' => $this->createdBy->getDisplayName(),
        ];
    }

    /**
     * Returns the venue name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Updates the venue name and refreshes the updatedAt timestamp.
     */
    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    /**
     * Returns the optional description, or null if none provided.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Updates the description notes and refreshes the updatedAt timestamp.
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
        $this->touch();
    }

    /**
     * Returns the physical street address, or null if not specified.
     */
    public function getAddress(): ?string
    {
        return $this->address;
    }

    /**
     * Updates the physical street address and refreshes the updatedAt timestamp.
     */
    public function setAddress(?string $address): void
    {
        $this->address = $address !== null ? trim($address) : null;
        $this->touch();
    }

    /**
     * Returns the latitude as a float for API consumers and map rendering.
     */
    #[Groups(['poi:read', 'poi:summary'])]
    public function getLatitude(): float
    {
        return (float) $this->latitude;
    }

    /**
     * Returns the longitude as a float for API consumers and map rendering.
     */
    #[Groups(['poi:read', 'poi:summary'])]
    public function getLongitude(): float
    {
        return (float) $this->longitude;
    }

    /**
     * Updates both decimal coordinates and synchronises the packed binary spatial POINT.
     */
    public function setCoordinates(float $latitude, float $longitude): void
    {
        $this->latitude = sprintf('%.7f', $latitude);
        $this->longitude = sprintf('%.7f', $longitude);
        $this->location = self::buildPackedPoint($latitude, $longitude);
        $this->touch();
    }

    /**
     * Returns the raw 25-byte packed WKB point geometry.
     */
    public function getLocation(): string
    {
        return $this->location;
    }

    /**
     * Returns true if the POI is published to the public homepage map.
     */
    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    /**
     * Updates public visibility and refreshes the updatedAt timestamp.
     */
    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->touch();
    }

    /**
     * Returns the optimistic locking version number.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Explicitly sets the version number (used for testing or hydration).
     */
    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Returns the collection of lists containing this POI.
     *
     * @return Collection<int, PoiList>
     */
    public function getLists(): Collection
    {
        return $this->lists;
    }

    /**
     * Associates this POI with a list if not already attached.
     */
    public function addList(PoiList $list): void
    {
        if (!$this->lists->contains($list)) {
            $this->lists->add($list);
            $this->touch();
        }
    }

    /**
     * Removes the association between this POI and a list.
     */
    public function removeList(PoiList $list): void
    {
        if ($this->lists->removeElement($list)) {
            $this->touch();
        }
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last modification timestamp.
     */
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Placeholder method for Phase 3: Returns attached user reviews.
     *
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getReviews(): array
    {
        return [];
    }

    /**
     * Placeholder method for Phase 3: Returns discussion comments.
     *
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getComments(): array
    {
        return [];
    }

    /**
     * Placeholder method for Phase 3: Returns uploaded food photos.
     *
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getPhotos(): array
    {
        return [];
    }

    /**
     * Placeholder method for Phase 3: Returns emoji reactions.
     *
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getReactions(): array
    {
        return [];
    }

    /**
     * Updates the updatedAt timestamp to current time on mutation.
     */
    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
