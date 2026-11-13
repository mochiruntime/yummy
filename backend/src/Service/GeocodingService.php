<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeocodeCache;
use App\Repository\GeocodeCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side geocoding and place autocomplete service with database caching.
 *
 * Privacy & Upstream Architecture:
 * - Prevents client IP leakage to third parties by proxying search requests through the backend.
 * - Multi-provider failover: Queries Photon (Komoot/OSM) as primary provider for fast fuzzy autocomplete;
 *   falls back to Nominatim (OpenStreetMap) if Photon is unavailable or returns no results.
 * - Caching: Responses are cached in MariaDB for 30 days under a SHA-256 hash of the normalized query
 *   and coordinates, minimizing external network requests and respecting fair use policies.
 */
final class GeocodingService
{
    /**
     * Cache retention period for autocomplete results.
     */
    private const CACHE_TTL = '+30 days';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeocodeCacheRepository $cacheRepo,
        private readonly HttpClientInterface $httpClient,
        private readonly string $photonBaseUrl = 'https://photon.komoot.io',
        private readonly string $nominatimBaseUrl = 'https://nominatim.openstreetmap.org',
    ) {
    }

    /**
     * Searches for locations matching a text query, with optional coordinate biasing.
     *
     * @param string     $query Query text (e.g., venue name, street, or city)
     * @param float|null $lat   Optional viewer latitude for proximity biasing
     * @param float|null $lng   Optional viewer longitude for proximity biasing
     *
     * @return list<array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    public function search(string $query, ?float $lat = null, ?float $lng = null): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        // Coordinates are rounded to 4 decimal places (~11m precision) so searches from nearby viewports share cache hits
        $hashKey = hash('sha256', sprintf('%s|%.4f|%.4f', $normalized, $lat ?? 0.0, $lng ?? 0.0));
        $cached = $this->cacheRepo->findValid($hashKey);
        if ($cached !== null) {
            /** @var list<array{label: string, address: ?string, latitude: float, longitude: float}> $cachedList */
            $cachedList = array_values($cached->getResponseJson());

            return $cachedList;
        }

        // 1. Try primary provider: Photon (fast, typo-tolerant OpenStreetMap autocomplete)
        $results = $this->queryPhoton($normalized, $lat, $lng);

        // 2. Fall back to Nominatim if Photon failed, timed out, or returned no features
        if ($results === []) {
            $results = $this->queryNominatim($normalized);
        }

        // Persist valid results into database cache
        $entry = new GeocodeCache(
            $hashKey,
            'photon',
            $results,
            new \DateTimeImmutable(self::CACHE_TTL),
        );

        $this->em->persist($entry);
        $this->em->flush();

        return $results;
    }

    /**
     * Queries the Photon geocoding API.
     *
     * @param string     $query Cleaned search string
     * @param float|null $lat   Proximity latitude
     * @param float|null $lng   Proximity longitude
     *
     * @return list<array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    private function queryPhoton(string $query, ?float $lat, ?float $lng): array
    {
        try {
            $params = ['q' => $query, 'limit' => 10];
            if ($lat !== null && $lng !== null) {
                $params['lat'] = $lat;
                $params['lon'] = $lng;
            }

            $response = $this->httpClient->request('GET', rtrim($this->photonBaseUrl, '/').'/api/', [
                'query' => $params,
                'timeout' => 3.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = $response->toArray();
            $features = is_array($data['features'] ?? null) ? $data['features'] : [];
            $output = [];

            foreach ($features as $feature) {
                $props = $feature['properties'] ?? [];
                $coords = $feature['geometry']['coordinates'] ?? null;

                // GeoJSON coordinates are ordered [longitude, latitude]
                if (!is_array($coords) || count($coords) < 2) {
                    continue;
                }

                $name = (string) ($props['name'] ?? $props['street'] ?? $query);
                $addressParts = array_filter([
                    $props['street'] ?? null,
                    $props['housenumber'] ?? null,
                    $props['city'] ?? $props['town'] ?? $props['village'] ?? null,
                    $props['country'] ?? null,
                ]);

                $output[] = [
                    'label' => $name,
                    'address' => $addressParts !== [] ? implode(', ', $addressParts) : null,
                    'latitude' => (float) $coords[1],
                    'longitude' => (float) $coords[0],
                ];
            }

            return $output;
        } catch (\Throwable) {
            // Gracefully catch timeouts or network errors to trigger Nominatim fallback
            return [];
        }
    }

    /**
     * Queries the OpenStreetMap Nominatim search API as fallback.
     *
     * @param string $query Cleaned search string
     *
     * @return list<array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    private function queryNominatim(string $query): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->nominatimBaseUrl, '/').'/search', [
                'query' => [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'limit' => 10,
                ],
                'headers' => [
                    // Nominatim usage policy strictly requires a custom, non-generic User-Agent header
                    'User-Agent' => 'yummy-map-app/1.0',
                ],
                'timeout' => 3.0,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $items = $response->toArray();
            $output = [];

            foreach ($items as $item) {
                if (!isset($item['lat'], $item['lon'])) {
                    continue;
                }

                $output[] = [
                    'label' => (string) ($item['name'] ?? $item['display_name'] ?? $query),
                    'address' => isset($item['display_name']) ? (string) $item['display_name'] : null,
                    'latitude' => (float) $item['lat'],
                    'longitude' => (float) $item['lon'],
                ];
            }

            return $output;
        } catch (\Throwable) {
            return [];
        }
    }
}
