<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for updating an existing POI (requires optimistic lock version).
 */
final class PoiUpdateInputDto
{
    #[Assert\NotNull(message: 'Version is required for optimistic concurrency control.')]
    #[Assert\Positive(message: 'Version must be a positive integer.')]
    public int $version = 1;

    #[Assert\Length(min: 1, max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 10000)]
    public ?string $description = null;

    #[Assert\Length(max: 500)]
    public ?string $address = null;

    #[Assert\Range(min: -90.0, max: 90.0)]
    public ?float $latitude = null;

    #[Assert\Range(min: -180.0, max: 180.0)]
    public ?float $longitude = null;
}
