<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GeocodeCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Caches place autocomplete results to protect client IP addresses and respect upstream rate limits.
 *
 * Privacy & Performance Model:
 * - Direct client-side calls to external geocoding APIs expose client IP addresses and locations.
 * - By proxying and caching results in MariaDB under a SHA-256 hash of normalized queries, repeated
 *   searches avoid network roundtrips and respect OpenStreetMap / Komoot fair use policies.
 */
#[ORM\Entity(repositoryClass: GeocodeCacheRepository::class)]
#[ORM\Table(name: 'geocode_cache')]
class GeocodeCache
{
    /**
     * SHA-256 hash (64 hex characters) of the normalized query string and rounded coordinates.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'query_hash', length: 64)]
    private string $queryHash;

    /**
     * Upstream geocoding provider identifier ("photon" or "nominatim"). Max 32 characters.
     */
    #[ORM\Column(length: 32)]
    private string $provider;

    /**
     * Normalized array of autocomplete suggestions matching the query.
     *
     * @var array<int, array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    #[ORM\Column(name: 'response_json', type: Types::JSON)]
    private array $responseJson;

    /**
     * Expiration timestamp after which the cached entry is considered stale.
     */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /**
     * Creates a new cached geocode entry.
     *
     * @param string                                                                                $queryHash    SHA-256 hash of normalized search params
     * @param string                                                                                $provider     Provider identifier ("photon" or "nominatim")
     * @param array<int, array{label: string, address: ?string, latitude: float, longitude: float}> $responseJson Formatted list of place results
     * @param \DateTimeImmutable                                                                    $expiresAt    Expiry deadline
     */
    public function __construct(string $queryHash, string $provider, array $responseJson, \DateTimeImmutable $expiresAt)
    {
        $this->queryHash = $queryHash;
        $this->provider = $provider;
        $this->responseJson = $responseJson;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Returns the SHA-256 hash key identifying this query.
     */
    public function getQueryHash(): string
    {
        return $this->queryHash;
    }

    /**
     * Returns the provider identifier ("photon" or "nominatim").
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Returns the cached structured autocomplete place results.
     *
     * @return array<int, array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    public function getResponseJson(): array
    {
        return $this->responseJson;
    }

    /**
     * Returns the expiration datetime.
     */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Checks if this cache entry has expired relative to the specified reference time.
     *
     * @param \DateTimeImmutable $now Reference time (defaults to current time)
     */
    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }
}
