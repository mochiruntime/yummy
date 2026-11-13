<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for creating a new Point of Interest (POI).
 */
final class PoiCreateInputDto
{
    /**
     * Venue or restaurant name (e.g. "Ramen Ya").
     */
    #[Assert\NotBlank(message: 'POI name is required.')]
    #[Assert\Length(min: 1, max: 255, maxMessage: 'POI name cannot exceed 255 characters.')]
    public string $name = '';

    /**
     * Optional editorial notes, culinary highlights, or tips.
     */
    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    /**
     * Optional physical street address or place description.
     */
    #[Assert\Length(max: 500)]
    public ?string $address = null;

    /**
     * WGS 84 latitude in degrees (-90.0 to +90.0).
     */
    #[Assert\NotNull(message: 'Latitude is required.')]
    #[Assert\Range(min: -90.0, max: 90.0)]
    public float $latitude = 0.0;

    /**
     * WGS 84 longitude in degrees (-180.0 to +180.0).
     */
    #[Assert\NotNull(message: 'Longitude is required.')]
    #[Assert\Range(min: -180.0, max: 180.0)]
    public float $longitude = 0.0;

    /**
     * Optional list of existing category UUIDs to associate with this POI on creation.
     *
     * @var list<string>
     */
    #[Assert\All([
        new Assert\Uuid(message: 'Each list ID must be a valid UUID.'),
    ])]
    public array $listIds = [];

    /**
     * Whether this POI should be visible on the public map to unauthenticated guests (default false).
     */
    public bool $isPublic = false;
}
