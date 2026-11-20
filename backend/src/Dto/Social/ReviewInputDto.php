<?php

declare(strict_types=1);

namespace App\Dto\Social;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for creating a new review on a POI.
 */
final class ReviewInputDto
{
    /**
     * Star rating between 1 and 5 inclusive.
     */
    #[Assert\NotNull(message: 'Rating is required.')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Rating must be between 1 and 5.')]
    public int $rating = 5;

    /**
     * Optional written review commentary.
     */
    #[Assert\Length(max: 10000, maxMessage: 'Review body cannot exceed 10000 characters.')]
    public ?string $body = null;

    /**
     * Whether this review should be visible to unauthenticated guests when the parent POI is public (default false).
     */
    public bool $isPublic = false;

    /**
     * Optional spend tier from 1 ($) to 4 ($$$$).
     */
    #[Assert\Range(min: 1, max: 4, notInRangeMessage: 'Price level must be between 1 and 4.')]
    public ?int $priceLevel = null;

    /**
     * Optional ISO 4217 currency code (e.g. "CHF", "EUR", "USD").
     */
    #[Assert\Regex(pattern: '/^[A-Za-z]{3}$/', message: 'Currency must be a 3-letter ISO 4217 code.')]
    public ?string $currency = null;

    /**
     * Optional list of quick-tap vibe tags (up to 10 tags, max 40 characters each).
     *
     * @var list<string>
     */
    #[Assert\Count(max: 10, maxMessage: 'You can select at most 10 vibes.')]
    #[Assert\All([
        new Assert\Type('string'),
        new Assert\NotBlank(message: 'Vibe tags cannot be blank.'),
        new Assert\Length(max: 40, maxMessage: 'Each vibe tag cannot exceed 40 characters.'),
    ])]
    public array $vibes = [];

    /**
     * Optional list of ordered dishes, drinks, or menu items reviewed (up to 30 items).
     *
     * @var list<ReviewItemInputDto>
     */
    #[Assert\Count(max: 30, maxMessage: 'You can add at most 30 items per review.')]
    #[Assert\Valid]
    public array $items = [];

    /**
     * Adder used by Symfony PropertyInfo/Serializer to hydrate nested ReviewItemInputDto elements.
     */
    public function addItem(ReviewItemInputDto $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Remover paired with addItem for Symfony PropertyAccessor compatibility.
     */
    public function removeItem(ReviewItemInputDto $item): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn (ReviewItemInputDto $existing): bool => $existing !== $item,
        ));
    }
}
