<?php

declare(strict_types=1);

namespace App\Dto\List;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for creating or updating a POI list/category.
 */
final class PoiListInputDto
{
    #[Assert\NotBlank(message: 'List name is required.')]
    #[Assert\Length(min: 1, max: 80, maxMessage: 'List name cannot exceed 80 characters.')]
    public string $name = '';

    #[Assert\Length(max: 16)]
    public string $color = '#E11D48';

    #[Assert\Length(max: 32)]
    public string $icon = 'bookmark';
}
