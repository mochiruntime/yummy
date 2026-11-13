<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload DTO for updating an existing Point of Interest (POI).
 *
 * Employs optimistic concurrency control: the client must provide its currently known
 * version number. If another edit occurred in the interim, the update is rejected with 409 Conflict.
 */
final class PoiUpdateInputDto
{
    /**
     * Currently known version counter of the POI for optimistic concurrency checking.
     */
    #[Assert\NotNull(message: 'Version is required for optimistic concurrency control.')]
    #[Assert\Positive(message: 'Version must be a positive integer.')]
    public int $version = 1;

    /**
     * Updated venue name, or null to keep unchanged.
     */
    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    /**
     * Updated notes/description, or null to keep unchanged.
     */
    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    /**
     * Updated street address, or null to keep unchanged.
     */
    #[Assert\Length(max: 500)]
    public ?string $address = null;

    /**
     * Updated WGS 84 latitude, or null to keep unchanged.
     */
    #[Assert\Range(min: -90.0, max: 90.0)]
    public ?float $latitude = null;

    /**
     * Updated WGS 84 longitude, or null to keep unchanged.
     */
    #[Assert\Range(min: -180.0, max: 180.0)]
    public ?float $longitude = null;
}
