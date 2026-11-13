<?php

declare(strict_types=1);

namespace App\Dto\List;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for creating or updating a curated POI list / category.
 */
final class PoiListInputDto
{
    /**
     * Human-readable list name (e.g. "Best Bakeries").
     */
    #[Assert\NotBlank(message: 'List name is required.')]
    #[Assert\Length(min: 1, max: 80, maxMessage: 'List name cannot exceed 80 characters.')]
    public string $name = '';

    /**
     * Hexadecimal color code used for marker/list accent styling (e.g. "#E11D48").
     */
    #[Assert\Length(max: 16)]
    public string $color = '#E11D48';

    /**
     * Icon identifier name from the Lucide icon library (e.g. "bookmark", "utensils").
     */
    #[Assert\Length(max: 32)]
    public string $icon = 'bookmark';
}
