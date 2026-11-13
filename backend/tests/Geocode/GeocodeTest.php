<?php

declare(strict_types=1);

namespace App\Tests\Geocode;

use App\Entity\GeocodeCache;
use App\Entity\User;
use App\Repository\GeocodeCacheRepository;
use App\Service\GeocodingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for the geocoding endpoint, Photon provider, Nominatim fallback, and database caching.
 */
final class GeocodeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->truncateTables();
    }

    public function testGeocodeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/geocode?q=Paris');
        self::assertResponseStatusCodeSame(401);
    }

    public function testGeocodeRejectsShortQuery(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/geocode?q=a');
        self::assertResponseStatusCodeSame(400);
    }

    public function testGeocodeReturnsCachedResults(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);

        $query = 'eiffel tower';
        $hashKey = hash('sha256', sprintf('%s|%.4f|%.4f', $query, 0.0, 0.0));
        $cachedData = [
            [
                'label' => 'Eiffel Tower',
                'address' => 'Champ de Mars, Paris, France',
                'latitude' => 48.8584,
                'longitude' => 2.2945,
            ],
        ];

        $cache = new GeocodeCache(
            $hashKey,
            'photon',
            $cachedData,
            new \DateTimeImmutable('+30 days'),
        );
        $this->em->persist($cache);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/api/geocode?q=Eiffel+Tower');
        self::assertResponseIsSuccessful();

        $results = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($results);
        self::assertCount(1, $results);
        self::assertSame('Eiffel Tower', $results[0]['label']);
        self::assertSame(48.8584, $results[0]['latitude']);
        self::assertSame(2.2945, $results[0]['longitude']);
    }

    public function testPhotonParsingAndFallbackToNominatim(): void
    {
        $cacheRepo = $this->em->getRepository(GeocodeCache::class);
        \assert($cacheRepo instanceof GeocodeCacheRepository);

        // Case 1: Photon returns valid GeoJSON feature collection
        $photonJson = json_encode([
            'features' => [
                [
                    'geometry' => [
                        'coordinates' => [2.3522, 48.8566],
                    ],
                    'properties' => [
                        'name' => 'Cafe de Flore',
                        'street' => 'Boulevard Saint-Germain',
                        'housenumber' => '172',
                        'city' => 'Paris',
                        'country' => 'France',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $mockHttp = new MockHttpClient([
            new MockResponse($photonJson),
        ]);

        $service = new GeocodingService(
            $this->em,
            $cacheRepo,
            $mockHttp,
            'https://photon.komoot.io',
            'https://nominatim.openstreetmap.org',
        );

        $results = $service->search('cafe flore', 48.85, 2.35);
        self::assertCount(1, $results);
        self::assertSame('Cafe de Flore', $results[0]['label']);
        self::assertSame(48.8566, $results[0]['latitude']);
        self::assertSame(2.3522, $results[0]['longitude']);
        self::assertStringContainsString('Boulevard Saint-Germain', (string) $results[0]['address']);

        // Case 2: Photon fails (500), falls back to Nominatim
        $nominatimJson = json_encode([
            [
                'name' => 'Chez Janou',
                'display_name' => 'Chez Janou, 2 Rue Roger Verlomme, Paris, France',
                'lat' => '48.8540',
                'lon' => '2.3670',
            ],
        ], JSON_THROW_ON_ERROR);

        $fallbackHttp = new MockHttpClient([
            new MockResponse('Internal Server Error', ['http_code' => 500]),
            new MockResponse($nominatimJson),
        ]);

        $fallbackService = new GeocodingService(
            $this->em,
            $cacheRepo,
            $fallbackHttp,
            'https://photon.komoot.io',
            'https://nominatim.openstreetmap.org',
        );

        $fallbackResults = $fallbackService->search('chez janou');
        self::assertCount(1, $fallbackResults);
        self::assertSame('Chez Janou', $fallbackResults[0]['label']);
        self::assertSame(48.8540, $fallbackResults[0]['latitude']);
        self::assertSame(2.3670, $fallbackResults[0]['longitude']);

        // Empty query returns []
        self::assertSame([], $fallbackService->search('   '));

        // Test GeocodeCache getters
        $entry = new GeocodeCache('test-hash', 'photon', [], new \DateTimeImmutable('+1 hour'));
        self::assertSame('test-hash', $entry->getQueryHash());
        self::assertSame('photon', $entry->getProvider());
        self::assertFalse($entry->isExpired());
        self::assertInstanceOf(\DateTimeImmutable::class, $entry->getExpiresAt());
    }

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->executeStatement('TRUNCATE TABLE poi_revisions');
        $conn->executeStatement('TRUNCATE TABLE poi_lists');
        $conn->executeStatement('TRUNCATE TABLE pois');
        $conn->executeStatement('TRUNCATE TABLE lists');
        $conn->executeStatement('TRUNCATE TABLE geocode_cache');
        $conn->executeStatement('TRUNCATE TABLE magic_link_tokens');
        $conn->executeStatement('TRUNCATE TABLE invite_tokens');
        $conn->executeStatement('TRUNCATE TABLE users');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
