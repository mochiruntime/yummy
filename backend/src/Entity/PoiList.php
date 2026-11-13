<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PoiListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a collaborative list / category of POIs (e.g., "Trip to Portugal 2026", "Best Pizza", "Date Night").
 *
 * Scoping & Ownership:
 * - Each list belongs to an owner (User). Slugs are unique per owner (owner_id + slug), allowing
 *   different users to create lists with identical names (e.g., "Favorites") without collision.
 * - Only the list owner or an instance Admin can modify the list metadata or delete the list.
 */
#[ORM\Entity(repositoryClass: PoiListRepository::class)]
#[ORM\Table(name: 'lists')]
#[ORM\UniqueConstraint(name: 'uniq_lists_owner_slug', columns: ['owner_id', 'slug'])]
class PoiList
{
    /**
     * Unique identifier using time-ordered UUIDv7.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private Uuid $id;

    /**
     * Owner of the list who created and manages its metadata.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /**
     * Human-readable list name (e.g., "Best Ramen in Berlin"). Max 80 characters.
     */
    #[ORM\Column(length: 80)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $name;

    /**
     * URL-safe slug unique per owner (e.g., "best-ramen-in-berlin"). Max 100 characters.
     */
    #[ORM\Column(length: 100)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $slug;

    /**
     * Hexadecimal color code used for map marker accents (e.g., "#E11D48"). Max 16 characters.
     */
    #[ORM\Column(length: 16)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $color;

    /**
     * Lucide/Feather icon identifier for list badges (e.g., "bookmark", "utensils"). Max 32 characters.
     */
    #[ORM\Column(length: 32)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $icon;

    /**
     * POIs contained within this list.
     *
     * @var Collection<int, Poi>
     */
    #[ORM\ManyToMany(targetEntity: Poi::class, mappedBy: 'lists')]
    private Collection $pois;

    /**
     * Timestamp when the list was created.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['list:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new POI list instance.
     *
     * @param User   $owner List owner
     * @param string $name  Display name
     * @param string $slug  Owner-scoped unique slug
     * @param string $color Hex color code (#rrggbb)
     * @param string $icon  Icon identifier string
     */
    public function __construct(User $owner, string $name, string $slug, string $color = '#E11D48', string $icon = 'bookmark')
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = trim($name);
        $this->slug = trim($slug);
        $this->color = trim($color);
        $this->icon = trim($icon);
        $this->pois = new ArrayCollection();
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
     * Returns the user entity who owns this list.
     */
    public function getOwner(): User
    {
        return $this->owner;
    }

    /**
     * Returns the RFC 4122 string representation of the owner's UUID for API responses.
     */
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    #[SerializedName('ownerId')]
    public function getOwnerId(): string
    {
        return $this->owner->getId()->toRfc4122();
    }

    /**
     * Returns the display name of the owner for API responses.
     */
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    #[SerializedName('ownerName')]
    public function getOwnerName(): string
    {
        return $this->owner->getDisplayName();
    }

    /**
     * Returns the human-readable list name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Updates the human-readable list name.
     */
    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    /**
     * Returns the URL-safe slug.
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Updates the URL-safe slug.
     */
    public function setSlug(string $slug): void
    {
        $this->slug = trim($slug);
    }

    /**
     * Returns the hex color string.
     */
    public function getColor(): string
    {
        return $this->color;
    }

    /**
     * Updates the hex color string.
     */
    public function setColor(string $color): void
    {
        $this->color = trim($color);
    }

    /**
     * Returns the icon identifier string.
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * Updates the icon identifier string.
     */
    public function setIcon(string $icon): void
    {
        $this->icon = trim($icon);
    }

    /**
     * Returns the collection of POIs attached to this list.
     *
     * @return Collection<int, Poi>
     */
    public function getPois(): Collection
    {
        return $this->pois;
    }

    /**
     * Returns the timestamp when the list was created.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
