<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GeocodeCacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Caches place autocomplete results to protect client IP addresses and respect upstream rate limits.
 */
#[ORM\Entity(repositoryClass: GeocodeCacheRepository::class)]
#[ORM\Table(name: 'geocode_cache')]
class GeocodeCache
{
    #[ORM\Id]
    #[ORM\Column(name: 'query_hash', length: 64)]
    private string $queryHash;

    #[ORM\Column(length: 32)]
    private string $provider;

    /**
     * @var array<int, array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    #[ORM\Column(name: 'response_json', type: Types::JSON)]
    private array $responseJson;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param array<int, array{label: string, address: ?string, latitude: float, longitude: float}> $responseJson
     */
    public function __construct(string $queryHash, string $provider, array $responseJson, \DateTimeImmutable $expiresAt)
    {
        $this->queryHash = $queryHash;
        $this->provider = $provider;
        $this->responseJson = $responseJson;
        $this->expiresAt = $expiresAt;
    }

    public function getQueryHash(): string
    {
        return $this->queryHash;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @return array<int, array{label: string, address: ?string, latitude: float, longitude: float}>
     */
    public function getResponseJson(): array
    {
        return $this->responseJson;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }
}
