<?php

declare(strict_types=1);

namespace App\Dto\Geocode;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated query-string DTO for geocoding and place autocomplete requests.
 */
final class GeocodeQueryDto
{
    /**
     * Search query string (minimum 2 characters after trimming).
     */
    #[Assert\NotBlank(message: 'Query parameter "q" must be at least 2 characters.')]
    #[Assert\Length(min: 2, minMessage: 'Query parameter "q" must be at least 2 characters.', normalizer: 'trim')]
    public string $q = '';

    /**
     * Optional viewport center latitude (-90.0 to +90.0) for location bias.
     */
    #[Assert\Range(min: -90.0, max: 90.0, notInRangeMessage: 'Latitude must be between -90 and 90.')]
    public ?float $lat = null;

    /**
     * Optional viewport center longitude (-180.0 to +180.0) for location bias.
     */
    #[Assert\Range(min: -180.0, max: 180.0, notInRangeMessage: 'Longitude must be between -180 and 180.')]
    public ?float $lng = null;

    /**
     * Returns the trimmed search query string.
     */
    public function getTrimmedQuery(): string
    {
        return trim($this->q);
    }
}
