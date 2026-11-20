<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReviewItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents an individual dish, drink, or order item reviewed as part of a parent Review.
 *
 * Allows members to rate specific items ("must_get", "good", "skip") with optional pricing,
 * tasting notes, and dish-specific photos attached via Photo::$reviewItem.
 */
#[ORM\Entity(repositoryClass: ReviewItemRepository::class)]
#[ORM\Table(name: 'review_items')]
#[ORM\Index(name: 'idx_review_items_review_sort', columns: ['review_id', 'sort_order'])]
class ReviewItem
{
    public const VERDICT_MUST_GET = 'must_get';
    public const VERDICT_GOOD = 'good';
    public const VERDICT_SKIP = 'skip';

    /**
     * Unique identifier using time-ordered UUIDv7 stored as BINARY(16).
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['review:read', 'poi:read'])]
    private Uuid $id;

    /**
     * Parent review this ordered item belongs to. Deleted via CASCADE when the review is deleted.
     */
    #[ORM\ManyToOne(targetEntity: Review::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'review_id', nullable: false, onDelete: 'CASCADE')]
    private Review $review;

    /**
     * Name of the dish, drink, or menu item (VARCHAR(255), non-null).
     */
    #[ORM\Column(length: 255)]
    #[Groups(['review:read', 'poi:read'])]
    private string $name;

    /**
     * Quick verdict tag ("must_get", "good", or "skip"), stored as VARCHAR(16), non-null.
     */
    #[ORM\Column(length: 16)]
    #[Groups(['review:read', 'poi:read'])]
    private string $verdict;

    /**
     * Optional item price formatted as a decimal string (DECIMAL(10,2), nullable) in the parent review's currency.
     */
    #[ORM\Column(name: 'price_amount', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['review:read', 'poi:read'])]
    #[SerializedName('priceAmount')]
    private ?string $priceAmount = null;

    /**
     * Optional tasting note or tip for this specific item (VARCHAR(1000), nullable).
     */
    #[ORM\Column(length: 1000, nullable: true)]
    #[Groups(['review:read', 'poi:read'])]
    private ?string $note = null;

    /**
     * Zero-based display order within the parent review (INT, non-null, default 0).
     */
    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    #[Groups(['review:read', 'poi:read'])]
    #[SerializedName('sortOrder')]
    private int $sortOrder = 0;

    /**
     * Creates a new ReviewItem entity.
     *
     * @param Review      $review      Parent Review
     * @param string      $name        Dish or drink name
     * @param string      $verdict     Verdict identifier ("must_get", "good", or "skip")
     * @param string|null $priceAmount Optional decimal price string
     * @param string|null $note        Optional item note
     * @param int         $sortOrder   Zero-based display order
     */
    public function __construct(
        Review $review,
        string $name,
        string $verdict,
        ?string $priceAmount = null,
        ?string $note = null,
        int $sortOrder = 0,
    ) {
        $this->id = Uuid::v7();
        $this->review = $review;
        $this->name = trim($name);
        $this->verdict = $verdict;
        $this->priceAmount = $priceAmount !== null && trim($priceAmount) !== '' ? trim($priceAmount) : null;
        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->sortOrder = $sortOrder;
    }

    /**
     * Returns the UUIDv7 primary key.
     */
    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * Returns the parent Review entity.
     */
    public function getReview(): Review
    {
        return $this->review;
    }

    /**
     * Returns the dish or drink name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the verdict string ("must_get", "good", or "skip").
     */
    public function getVerdict(): string
    {
        return $this->verdict;
    }

    /**
     * Returns the optional decimal price string.
     */
    public function getPriceAmount(): ?string
    {
        return $this->priceAmount;
    }

    /**
     * Returns the optional item note.
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Returns the zero-based sort order within the review.
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
