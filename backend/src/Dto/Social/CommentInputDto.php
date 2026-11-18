<?php

declare(strict_types=1);

namespace App\Dto\Social;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for posting a discussion comment on a POI.
 */
final class CommentInputDto
{
    /**
     * Non-empty comment text (max 2000 characters).
     */
    #[Assert\NotBlank(message: 'Comment body is required.')]
    #[Assert\Length(min: 1, max: 2000, maxMessage: 'Comment cannot exceed 2000 characters.')]
    public string $body = '';

    /**
     * Whether this comment should be visible to unauthenticated guests when the parent POI is public (default false).
     */
    public bool $isPublic = false;
}
