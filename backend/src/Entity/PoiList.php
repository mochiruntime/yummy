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
 * Represents a collaborative list / category of POIs (e.g., "Trip to Portugal 2026", "Cafes").
 *
 * Each list is owned by a User. Only the list owner (or an Admin) can edit its metadata
 * or add/remove POIs from it.
 */
#[ORM\Entity(repositoryClass: PoiListRepository::class)]
#[ORM\Table(name: 'lists')]
#[ORM\UniqueConstraint(name: 'uniq_lists_owner_slug', columns: ['owner_id', 'slug'])]
class PoiList
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $name;

    #[ORM\Column(length: 100)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $slug;

    #[ORM\Column(length: 16)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $color;

    #[ORM\Column(length: 32)]
    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    private string $icon;

    /**
     * @var Collection<int, Poi>
     */
    #[ORM\ManyToMany(targetEntity: Poi::class, mappedBy: 'lists')]
    private Collection $pois;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['list:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

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

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    #[SerializedName('ownerId')]
    public function getOwnerId(): string
    {
        return $this->owner->getId()->toRfc4122();
    }

    #[Groups(['list:read', 'poi:read', 'poi:summary'])]
    #[SerializedName('ownerName')]
    public function getOwnerName(): string
    {
        return $this->owner->getDisplayName();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = trim($slug);
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = trim($color);
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = trim($icon);
    }

    /**
     * @return Collection<int, Poi>
     */
    public function getPois(): Collection
    {
        return $this->pois;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
