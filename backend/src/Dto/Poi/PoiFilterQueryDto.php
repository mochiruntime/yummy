<?php

declare(strict_types=1);

namespace App\Dto\Poi;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Validated query-string DTO for filtering POIs by bounding box and category list IDs.
 */
final class PoiFilterQueryDto
{
    /**
     * Optional viewport bounding box formatted as "minLng,minLat,maxLng,maxLat".
     */
    public ?string $bbox = null;

    /**
     * Optional comma-separated list of category UUIDs to filter by.
     */
    public ?string $listIds = null;

    /**
     * Validates the bounding box format and WGS 84 coordinate bounds.
     */
    #[Assert\Callback]
    public function validateBbox(ExecutionContextInterface $context): void
    {
        if ($this->bbox === null || trim($this->bbox) === '') {
            return;
        }

        $parsed = $this->parseBboxParts();
        if ($parsed === null) {
            $context->buildViolation('Invalid bbox format. Expected minLng,minLat,maxLng,maxLat.')
                ->atPath('bbox')
                ->addViolation();

            return;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $parsed;
        if ($minLat < -90.0 || $maxLat > 90.0 || $minLng < -180.0 || $maxLng > 180.0 || $minLat > $maxLat || $minLng > $maxLng) {
            $context->buildViolation('Invalid bbox bounds.')
                ->atPath('bbox')
                ->addViolation();
        }
    }

    /**
     * Converts the validated bbox string into an associative bounds array, or null when omitted.
     *
     * @return array{minLng: float, minLat: float, maxLng: float, maxLat: float}|null
     */
    public function toBbox(): ?array
    {
        if ($this->bbox === null || trim($this->bbox) === '') {
            return null;
        }

        $parsed = $this->parseBboxParts();
        if ($parsed === null) {
            return null;
        }

        return [
            'minLng' => $parsed[0],
            'minLat' => $parsed[1],
            'maxLng' => $parsed[2],
            'maxLat' => $parsed[3],
        ];
    }

    /**
     * Parses the comma-separated listIds query parameter into a trimmed list of non-empty strings.
     *
     * @return list<string>
     */
    public function toListIds(): array
    {
        if ($this->listIds === null || trim($this->listIds) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->listIds)), static fn (string $id): bool => $id !== ''));
    }

    /**
     * Splits and casts a 4-part numeric bbox string into [minLng, minLat, maxLng, maxLat].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseBboxParts(): ?array
    {
        $parts = array_map('trim', explode(',', (string) $this->bbox));
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }

        return [
            (float) $parts[0],
            (float) $parts[1],
            (float) $parts[2],
            (float) $parts[3],
        ];
    }
}
