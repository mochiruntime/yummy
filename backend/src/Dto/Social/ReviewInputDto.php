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
}
