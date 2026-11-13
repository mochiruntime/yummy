<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for toggling the isPublic visibility flag.
 */
final class VisibilityInputDto
{
    #[Assert\NotNull]
    public bool $isPublic = false;
}
