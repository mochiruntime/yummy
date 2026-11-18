<?php

declare(strict_types=1);

namespace App\Dto\Social;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for toggling an emoji reaction on a POI.
 */
final class ReactionInputDto
{
    /**
     * UTF-8 emoji string (e.g., "🍕", "❤️", "🔥"). Max 16 characters.
     */
    #[Assert\NotBlank(message: 'Emoji is required.')]
    #[Assert\Length(min: 1, max: 16, maxMessage: 'Emoji cannot exceed 16 characters.')]
    public string $emoji = '';

    /**
     * Whether this reaction should be included in public guest aggregates when the parent POI is public (default false).
     */
    public bool $isPublic = false;
}
