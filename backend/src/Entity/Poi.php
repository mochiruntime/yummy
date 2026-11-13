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
 * Represents a Point of Interest (POI) on the map.
 *
 * Core attributes:
 * - Coordinates: Explicit decimal latitude and longitude (for portable display) and spatial POINT (for MBR bounding-box queries).
 * - Versioning: Optimistic concurrency control via $version, tracking changes in PoiRevision.
 * - Granular Visibility: Private by default; public POIs are viewable by unauthenticated visitors on the homepage.
 */
#[ORM\Entity(repositoryClass: PoiRepository::class)]
#[ORM\Table(name: 'pois')]
#[ORM\Index(name: 'idx_pois_location', columns: ['location'], flags: ['spatial'])]
class Poi
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['poi:read', 'poi:summary'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: 255)]
    #[Groups(['poi:read', 'poi:summary'])]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['poi:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['poi:read', 'poi:summary'])]
    private ?string $address = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $latitude;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7)]
    private string $longitude;

    /**
     * Managed spatial POINT geometry (SRID 4326) synced with latitude/longitude.
     */
    #[ORM\Column(type: 'string', columnDefinition: 'POINT NOT NULL /*!80003 SRID 4326 */')]
    private string $location;

    #[ORM\Column(name: 'is_public', type: Types::BOOLEAN)]
    #[Groups(['poi:read', 'poi:summary'])]
    #[SerializedName('isPublic')]
    private bool $isPublic = false;

    /**
     * Optimistic lock version counter. Incremented on every update.
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\Version]
    #[Groups(['poi:read', 'poi:summary'])]
    private int $version = 1;

    /**
     * @var Collection<int, PoiList>
     */
    #[ORM\ManyToMany(targetEntity: PoiList::class, inversedBy: 'pois')]
    #[ORM\JoinTable(name: 'poi_lists')]
    #[ORM\JoinColumn(name: 'poi_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'list_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['poi:read', 'poi:summary'])]
    private Collection $lists;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:read'])]
    #[SerializedName('updatedAt')]
    private \DateTimeImmutable $updatedAt;

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
        $this->location = pack('V', 4326).pack('C', 1).pack('V', 1).pack('e', $longitude).pack('e', $latitude);
        $this->isPublic = $isPublic;
        $this->lists = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    /**
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description !== null ? trim($description) : null;
        $this->touch();
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address !== null ? trim($address) : null;
        $this->touch();
    }

    #[Groups(['poi:read', 'poi:summary'])]
    public function getLatitude(): float
    {
        return (float) $this->latitude;
    }

    #[Groups(['poi:read', 'poi:summary'])]
    public function getLongitude(): float
    {
        return (float) $this->longitude;
    }

    public function setCoordinates(float $latitude, float $longitude): void
    {
        $this->latitude = sprintf('%.7f', $latitude);
        $this->longitude = sprintf('%.7f', $longitude);
        $this->location = pack('V', 4326).pack('C', 1).pack('V', 1).pack('e', $longitude).pack('e', $latitude);
        $this->touch();
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->touch();
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * @return Collection<int, PoiList>
     */
    public function getLists(): Collection
    {
        return $this->lists;
    }

    public function addList(PoiList $list): void
    {
        if (!$this->lists->contains($list)) {
            $this->lists->add($list);
            $this->touch();
        }
    }

    public function removeList(PoiList $list): void
    {
        if ($this->lists->removeElement($list)) {
            $this->touch();
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Placeholder collections for Phase 3 (reviews, comments, photos, reactions).
     *
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getReviews(): array
    {
        return [];
    }

    /**
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getComments(): array
    {
        return [];
    }

    /**
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getPhotos(): array
    {
        return [];
    }

    /**
     * @return list<mixed>
     */
    #[Groups(['poi:read'])]
    public function getReactions(): array
    {
        return [];
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
