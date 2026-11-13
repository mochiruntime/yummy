<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for updating the public visibility of a POI.
 */
final class VisibilityInputDto
{
    /**
     * Whether the POI is visible to unauthenticated visitors on the homepage map.
     */
    #[Assert\NotNull(message: 'isPublic boolean flag is required.')]
    public bool $isPublic = false;
}
