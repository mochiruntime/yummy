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
 * Immutable audit snapshot representing a specific revision/edit of a Point of Interest (POI).
 *
 * Audit & Reversion Architecture:
 * - Every edit to a POI records an immutable revision capturing the editor, revision sequence number,
 *   timestamp, and the full state snapshot/diff.
 * - Reverting to a past revision employs the "forward revert" pattern: instead of deleting subsequent
 *   history, a new revision N+1 is created with the past state, preserving complete audit lineage.
 */
#[ORM\Entity(repositoryClass: PoiRevisionRepository::class)]
#[ORM\Table(name: 'poi_revisions')]
#[ORM\UniqueConstraint(name: 'uniq_poi_revisions_poi_rev', columns: ['poi_id', 'revision_number'])]
class PoiRevision
{
    /**
     * Unique identifier using time-ordered UUIDv7.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['poi:revision'])]
    private Uuid $id;

    /**
     * The target POI entity being tracked.
     */
    #[ORM\ManyToOne(targetEntity: Poi::class)]
    #[ORM\JoinColumn(name: 'poi_id', nullable: false, onDelete: 'CASCADE')]
    private Poi $poi;

    /**
     * The user who performed this edit or initiated the revert.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'editor_id', nullable: false, onDelete: 'CASCADE')]
    private User $editor;

    /**
     * Sequential revision number matching the POI version at the time of modification.
     */
    #[ORM\Column(name: 'revision_number', type: Types::INTEGER)]
    #[Groups(['poi:revision'])]
    #[SerializedName('revisionNumber')]
    private int $revisionNumber;

    /**
     * JSON snapshot or structured diff containing the attributes modified or captured.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'changed_fields', type: Types::JSON)]
    #[Groups(['poi:revision'])]
    #[SerializedName('changedFields')]
    private array $changedFields;

    /**
     * Timestamp when this revision record was generated.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['poi:revision'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * Creates a new immutable revision record.
     *
     * @param Poi                  $poi            Target POI
     * @param User                 $editor         User making the modification
     * @param int                  $revisionNumber Incremental revision sequence number
     * @param array<string, mixed> $changedFields  Structured dictionary containing the updated fields or snapshot
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

    /**
     * Returns the revision's UUIDv7 primary key.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the associated POI.
     */
    public function getPoi(): Poi
    {
        return $this->poi;
    }

    /**
     * Returns the user who made this edit.
     */
    public function getEditor(): User
    {
        return $this->editor;
    }

    /**
     * Returns the display name of the editor for API responses.
     */
    #[Groups(['poi:revision'])]
    #[SerializedName('editorName')]
    public function getEditorName(): string
    {
        return $this->editor->getDisplayName();
    }

    /**
     * Returns the revision sequence number.
     */
    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    /**
     * Returns the JSON dictionary of changed fields or complete state snapshot.
     *
     * @return array<string, mixed>
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    /**
     * Returns the timestamp when this revision was recorded.
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
