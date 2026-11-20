<?php

declare(strict_types=1);

namespace App\Tests\Geocode;

use App\Entity\GeocodeCache;
use App\Entity\User;
use App\Repository\GeocodeCacheRepository;
use App\Service\GeocodingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for the geocoding endpoint, Photon provider, Nominatim fallback, database caching, and rate limiting.
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

        $rateLimiterCache = static::getContainer()->get('cache.rate_limiter');
        \assert($rateLimiterCache instanceof CacheItemPoolInterface);
        $rateLimiterCache->clear();
    }

    public function testGeocodeAllowsGuestWhenPublicBrowsingEnabled(): void
    {
        $hashKey = hash('sha256', sprintf('%s|%.4f|%.4f', 'paris', 0.0, 0.0));
        $cachedData = [
            [
                'label' => 'Paris, France',
                'address' => 'Île-de-France, France',
                'latitude' => 48.8566,
                'longitude' => 2.3522,
            ],
        ];
        $this->em->persist(new GeocodeCache($hashKey, 'photon', $cachedData, new \DateTimeImmutable('+30 days')));
        $this->em->flush();

        $this->client->request('GET', '/api/geocode?q=Paris');
        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('Paris, France', $data[0]['label']);
    }

    public function testGeocodeRateLimitsPublicGuestsAndIsolatesMemberBucket(): void
    {
        $hashKey = hash('sha256', sprintf('%s|%.4f|%.4f', 'paris', 0.0, 0.0));
        $cachedData = [
            [
                'label' => 'Paris, France',
                'address' => 'Île-de-France, France',
                'latitude' => 48.8566,
                'longitude' => 2.3522,
            ],
        ];
        $this->em->persist(new GeocodeCache($hashKey, 'photon', $cachedData, new \DateTimeImmutable('+30 days')));

        $member = new User('member@yummy.test', 'Member');
        $this->em->persist($member);
        $this->em->flush();

        for ($i = 0; $i < 40; ++$i) {
            $this->client->request('GET', '/api/geocode?q=Paris');
            self::assertResponseStatusCodeSame(200);
        }

        // 41st guest request from the same IP is rejected with 429
        $this->client->request('GET', '/api/geocode?q=Paris');
        self::assertResponseStatusCodeSame(429);
        $error = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $error);

        // Authenticated members use their own geocode_member bucket and are unaffected
        $this->client->loginUser($member);
        $this->client->request('GET', '/api/geocode?q=Paris');
        self::assertResponseStatusCodeSame(200);
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
                        'state' => 'Paris', // Identical to city -> should not duplicate
                        'country' => 'France',
                    ],
                ],
                [
                    'geometry' => [
                        'coordinates' => [6.6323, 46.5197],
                    ],
                    'properties' => [
                        'name' => 'Lausanne',
                        'state' => 'Vaud', // Distinct from city -> included for disambiguation
                        'country' => 'Switzerland',
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
        self::assertCount(2, $results);
        self::assertSame('Cafe de Flore', $results[0]['label']);
        self::assertSame(48.8566, $results[0]['latitude']);
        self::assertSame(2.3522, $results[0]['longitude']);
        self::assertSame('Boulevard Saint-Germain, 172, Paris, France', $results[0]['address']);
        self::assertSame('Lausanne', $results[1]['label']);
        self::assertSame('Vaud, Switzerland', $results[1]['address']);

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

        // Verify fallback cache entry recorded 'nominatim' as provider
        $nominatimCached = $this->em->getRepository(GeocodeCache::class)->findBy(['provider' => 'nominatim']);
        self::assertCount(1, $nominatimCached);
        $totalBeforeFailure = count($this->em->getRepository(GeocodeCache::class)->findAll());

        // Transient failure / empty results from both providers must NOT be cached
        $failingClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ]);
        $cacheRepo = $this->em->getRepository(GeocodeCache::class);
        \assert($cacheRepo instanceof \App\Repository\GeocodeCacheRepository);
        $failingService = new GeocodingService(
            $this->em,
            $cacheRepo,
            $failingClient,
            'https://photon.komoot.io',
            'https://nominatim.openstreetmap.org',
        );
        self::assertSame([], $failingService->search('unknown outage place'));
        self::assertCount($totalBeforeFailure, $this->em->getRepository(GeocodeCache::class)->findAll());

        // Expired cache entry for an existing query hash must refresh in place without a PRIMARY KEY collision
        $expiredHash = hash('sha256', sprintf('%s|%.4f|%.4f', 'expired cafe', 0.0, 0.0));
        $expiredEntry = new GeocodeCache(
            $expiredHash,
            'nominatim',
            [['label' => 'Old Name', 'address' => null, 'latitude' => 1.0, 'longitude' => 1.0]],
            new \DateTimeImmutable('-1 day'),
        );
        $this->em->persist($expiredEntry);
        $this->em->flush();

        $refreshClient = new MockHttpClient([
            new MockResponse((string) json_encode([
                'features' => [[
                    'properties' => ['name' => 'Refreshed Cafe', 'city' => 'Lyon'],
                    'geometry' => ['coordinates' => [4.8357, 45.7640]],
                ]],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $refreshService = new GeocodingService(
            $this->em,
            $cacheRepo,
            $refreshClient,
            'https://photon.komoot.io',
            'https://nominatim.openstreetmap.org',
        );
        $refreshedResults = $refreshService->search('expired cafe');
        self::assertCount(1, $refreshedResults);
        self::assertSame('Refreshed Cafe', $refreshedResults[0]['label']);
        $this->em->clear();
        $reloadedExpired = $cacheRepo->find($expiredHash);
        self::assertInstanceOf(GeocodeCache::class, $reloadedExpired);
        self::assertSame('photon', $reloadedExpired->getProvider());
        self::assertFalse($reloadedExpired->isExpired());

        // Empty query returns []
        self::assertSame([], $fallbackService->search('   '));

        // Test GeocodeCache getters
        $entry = new GeocodeCache('test-hash', 'photon', [], new \DateTimeImmutable('+1 hour'));
        self::assertSame('test-hash', $entry->getQueryHash());
        self::assertSame('photon', $entry->getProvider());
        self::assertFalse($entry->isExpired());
        self::assertInstanceOf(\DateTimeImmutable::class, $entry->getExpiresAt());
    }
}
