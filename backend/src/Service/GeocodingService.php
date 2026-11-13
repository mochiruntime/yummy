<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GeocodeCache;
use App\Repository\GeocodeCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-proxied, MariaDB-cached place search autocomplete.
 *
 * Dispatches to Photon (OSM) as primary, falling back to Nominatim on error/timeout,
 * and caches results in MariaDB to protect user privacy and respect upstream rate limits.
 */
final class GeocodingService
{
    private const CACHE_TTL = '+7 days';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeocodeCacheRepository $cacheRepo,
        private readonly HttpClientInterface $httpClient,
        private readonly string $photonBaseUrl = 'https://photon.komoot.io',
        private readonly string $nominatimBaseUrl = 'https://nominatim.openstreetmap.org',
    ) {
    }

    /**
     * @return list<array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    public function search(string $query, ?float $lat = null, ?float $lng = null): array
    {
        $normalized = mb_strtolower(trim($query));
        if ($normalized === '') {
            return [];
        }

        $hashKey = hash('sha256', sprintf('%s|%.4f|%.4f', $normalized, $lat ?? 0.0, $lng ?? 0.0));
        $cached = $this->cacheRepo->findValid($hashKey);
        if ($cached !== null) {
            /** @var list<array{label: string, address: ?string, latitude: float, longitude: float}> $cachedList */
            $cachedList = array_values($cached->getResponseJson());

            return $cachedList;
        }

        $results = $this->queryPhoton($normalized, $lat, $lng);
        if ($results === []) {
            $results = $this->queryNominatim($normalized);
        }

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
            return [];
        }
    }

    /**
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
