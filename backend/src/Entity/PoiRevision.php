<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PoiRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Immutable audit snapshot of an edit made to a POI.
 *
 * Captures the editor, revision number, timestamp, and changed fields diff/snapshot
 * to enable wiki-style history inspection and forward-reverting.
 */
#[ORM\Entity(repositoryClass: PoiRevisionRepository::class)]
#[ORM\Table(name: 'poi_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_poi_revisions_poi_rev', columns: ['poi_id', 'revision_number'])]
class PoiRevision
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['poi:revision'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'editor_id', nullable: false, onDelete: 'CASCADE')]
    private User $editor;

    #[ORM\Column(name: 'revision_number', type: Types::INTEGER)]
    #[Groups(['poi:revision'])]
    #[SerializedName('revisionNumber')]
    private int $revisionNumber;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'changed_fields', type: Types::JSON)]
    #[Groups(['poi:revision'])]
    #[SerializedName('changedFields')]
    private array $changedFields;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:revision'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $changedFields
     */
    public function __construct(Poi $poi, User $editor, int $revisionNumber, array $changedFields)
    {
        $this->id = Uuid::v7();
        $this->poi = $poi;
        $this->editor = $editor;
        $this->revisionNumber = $revisionNumber;
        $this->changedFields = $changedFields;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPoi(): Poi
    {
        return $this->poi;
    }

    public function getEditor(): User
    {
        return $this->editor;
    }

    #[Groups(['poi:revision'])]
    #[SerializedName('editorName')]
    public function getEditorName(): string
    {
        return $this->editor->getDisplayName();
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
