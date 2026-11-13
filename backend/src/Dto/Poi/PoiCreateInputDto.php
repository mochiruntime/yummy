<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for creating a new POI.
 */
final class PoiCreateInputDto
{
    #[Assert\NotBlank(message: 'POI name is required.')]
    #[Assert\Length(min: 1, max: 255, maxMessage: 'POI name cannot exceed 255 characters.')]
    public string $name = '';

    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    #[Assert\Length(max: 500)]
    public ?string $address = null;

    #[Assert\NotNull(message: 'Latitude is required.')]
    #[Assert\Range(min: -90.0, max: 90.0)]
    public float $latitude = 0.0;

    #[Assert\NotNull(message: 'Longitude is required.')]
    #[Assert\Range(min: -180.0, max: 180.0)]
    public float $longitude = 0.0;

    /**
     * @var list<string>
     */
    #[Assert\All([
        new Assert\Uuid(message: 'Each list ID must be a valid UUID.'),
    ])]
    public array $listIds = [];

    public bool $isPublic = false;
}
