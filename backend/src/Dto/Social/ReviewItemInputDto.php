<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Entity\ReviewItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated request payload DTO for an individual dish, drink, or order item within a review.
 */
final class ReviewItemInputDto
{
    /**
     * Name of the dish, drink, or menu item (1-255 characters).
     */
    #[Assert\NotBlank(message: 'Item name is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Item name cannot exceed 255 characters.')]
    public string $name = '';

    /**
     * Quick verdict tag ("must_get", "good", or "skip").
     */
    #[Assert\NotBlank(message: 'Item verdict is required.')]
    #[Assert\Choice(
        choices: [ReviewItem::VERDICT_MUST_GET, ReviewItem::VERDICT_GOOD, ReviewItem::VERDICT_SKIP],
        message: 'Verdict must be must_get, good, or skip.',
    )]
    public string $verdict = ReviewItem::VERDICT_GOOD;

    /**
     * Optional item price formatted as a non-negative decimal (up to 2 decimal places).
     */
    #[Assert\Regex(
        pattern: '/^\d{1,8}(\.\d{1,2})?$/',
        message: 'Price amount must be a valid non-negative decimal (up to 2 decimal places).',
    )]
    public ?string $priceAmount = null;

    /**
     * Optional tasting note or tip for this specific item (max 1000 characters).
     */
    #[Assert\Length(max: 1000, maxMessage: 'Item note cannot exceed 1000 characters.')]
    public ?string $note = null;
}
