<?php

declare(strict_types=1);

namespace App\Tests\Poi;

use App\Entity\Poi;
use App\Entity\PoiList;
use App\Entity\PoiRevision;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite covering POI creation, bounding-box querying,
 * guest vs authenticated visibility, optimistic locking conflicts, revisions, and reverts.
 */
final class PoiLifecycleTest extends WebTestCase
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

    public function testGuestCanOnlySeePublicPois(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $this->em->persist($creator);

        $privatePoi = new Poi($creator, 'Secret Bistro', 48.8584, 2.2945, 'Private dining', '1 Secret Alley', false);
        $publicPoi = new Poi($creator, 'Public Bakery', 48.8590, 2.2950, 'Famous baguettes', '2 Public Street', true);
        $this->em->persist($privatePoi);
        $this->em->persist($publicPoi);
        $this->em->flush();

        // Guest querying /api/pois
        $this->client->request('GET', '/api/pois');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('Public Bakery', $data[0]['name']);

        // Guest querying private POI directly -> 404
        $this->client->request('GET', '/api/pois/'.$privatePoi->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);

        // Guest querying public POI directly -> 200
        $this->client->request('GET', '/api/pois/'.$publicPoi->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
    }

    public function testAuthenticatedUserCanSeeAllPois(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $member = new User('member@yummy.test', 'Member');
        $this->em->persist($creator);
        $this->em->persist($member);

        $privatePoi = new Poi($creator, 'Secret Bistro', 48.8584, 2.2945, 'Private dining', '1 Secret Alley', false);
        $publicPoi = new Poi($creator, 'Public Bakery', 48.8590, 2.2950, 'Famous baguettes', '2 Public Street', true);
        $this->em->persist($privatePoi);
        $this->em->persist($publicPoi);
        $this->em->flush();

        $this->client->loginUser($member);
        $this->client->request('GET', '/api/pois');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);

        // Member querying private POI directly -> 200
        $this->client->request('GET', '/api/pois/'.$privatePoi->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
    }

    public function testBoundingBoxAndListFilters(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $this->em->persist($creator);

        $list = new PoiList($creator, 'Paris Cafe', 'paris-cafe', '#123456', 'coffee');
        $this->em->persist($list);

        // Paris coordinate: 48.8566, 2.3522
        $parisPoi = new Poi($creator, 'Parisian Cafe', 48.8566, 2.3522, null, null, true);
        $parisPoi->addList($list);

        // Tokyo coordinate: 35.6762, 139.6503
        $tokyoPoi = new Poi($creator, 'Tokyo Ramen', 35.6762, 139.6503, null, null, true);

        $this->em->persist($parisPoi);
        $this->em->persist($tokyoPoi);
        $this->em->flush();

        // Bbox covering Paris area: minLng=2.0, minLat=48.0, maxLng=3.0, maxLat=49.0
        $this->client->request('GET', '/api/pois?bbox=2.0,48.0,3.0,49.0');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(1, $data);
        self::assertSame('Parisian Cafe', $data[0]['name']);

        // Filter by listId
        $this->client->request('GET', '/api/pois?listIds='.$list->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $dataList = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($dataList);
        self::assertCount(1, $dataList);
        self::assertSame('Parisian Cafe', $dataList[0]['name']);

        // Invalid bbox
        $this->client->request('GET', '/api/pois?bbox=invalid');
        self::assertResponseStatusCodeSame(400);

        // Out of range bbox
        $this->client->request('GET', '/api/pois?bbox=100,200,300,400');
        self::assertResponseStatusCodeSame(400);
    }

    public function testCreatePoiWithRevision(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $this->em->persist($creator);

        $list = new PoiList($creator, 'Italian', 'italian', '#ff0000', 'pizza');
        $this->em->persist($list);
        $this->em->flush();

        $this->client->loginUser($creator);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/pois',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'name' => 'Trattoria Bella',
                'description' => 'Best pasta carbonara in the city.',
                'address' => '42 Via Roma',
                'latitude' => 41.9028,
                'longitude' => 12.4964,
                'isPublic' => false,
                'listIds' => [$list->getId()->toRfc4122()],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Trattoria Bella', $data['name']);
        self::assertSame(1, $data['version']);
        self::assertFalse($data['isPublic']);
        self::assertCount(1, $data['lists']);
        self::assertSame('Italian', $data['lists'][0]['name']);

        // Initial revision recorded in poi_revisions
        $poiId = $data['id'];
        $revRepo = $this->em->getRepository(PoiRevision::class);
        \assert($revRepo instanceof \App\Repository\PoiRevisionRepository);
        $savedPoi = $this->em->getRepository(Poi::class)->find(Uuid::fromString($poiId));
        \assert($savedPoi instanceof Poi);
        $revisions = $revRepo->findByPoiOrderedByRevisionDesc($savedPoi);
        self::assertCount(1, $revisions);
        self::assertSame(1, $revisions[0]->getRevisionNumber());
        self::assertTrue($revisions[0]->getChangedFields()['initial'] ?? false);
    }

    public function testOptimisticLockingOnUpdate(): void
    {
        $user1 = new User('alice@yummy.test', 'Alice');
        $user2 = new User('bob@yummy.test', 'Bob');
        $this->em->persist($user1);
        $this->em->persist($user2);

        $poi = new Poi($user1, 'Noodle Bar', 48.85, 2.35, 'Delicious noodles');
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Alice updates version 1 -> success, version becomes 2
        $this->client->loginUser($user1);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'version' => 1,
                'name' => 'Authentic Noodle Bar',
                'description' => 'Updated broth description',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertSame(2, $updated['version']);
        self::assertSame('Authentic Noodle Bar', $updated['name']);

        // Bob submits stale version 1 -> 409 Conflict
        $this->client->loginUser($user2);
        $csrfBob = $this->fetchCsrfToken();

        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode([
                'version' => 1,
                'name' => 'Bobs Noodle Bar',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(409);

        // Bob fetches latest version and submits version 2 -> success, version becomes 3
        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode([
                'version' => 2,
                'name' => 'Bobs Noodle Bar',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $updatedBob = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($updatedBob);
        self::assertSame(3, $updatedBob['version']);
        self::assertSame('Bobs Noodle Bar', $updatedBob['name']);
    }

    public function testVisibilityTogglePermissions(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $other = new User('other@yummy.test', 'Other');
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($creator);
        $this->em->persist($other);
        $this->em->persist($admin);

        $poi = new Poi($creator, 'Test Place', 48.0, 2.0, null, null, false);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Other user attempts to toggle -> 403
        $this->client->loginUser($other);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        // Creator toggles -> 200
        $this->client->loginUser($creator);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $res = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($res);
        self::assertTrue($res['isPublic']);

        // Admin toggles -> 200
        $this->client->loginUser($admin);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $resAdmin = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($resAdmin);
        self::assertFalse($resAdmin['isPublic']);
    }

    public function testDeletePoiPermissions(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $other = new User('other@yummy.test', 'Other');
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($creator);
        $this->em->persist($other);
        $this->em->persist($admin);

        $poi = new Poi($creator, 'To Delete', 48.0, 2.0);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Other attempts delete -> 403
        $this->client->loginUser($other);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'DELETE',
            '/api/pois/'.$poiId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        // Creator deletes -> 204
        $this->client->loginUser($creator);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'DELETE',
            '/api/pois/'.$poiId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Poi::class)->find(Uuid::fromString($poiId)));
    }

    public function testRevisionsListAndRevert(): void
    {
        $creator = new User('creator@yummy.test', 'Creator');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($creator);
        $this->em->persist($other);

        $poi = new Poi($creator, 'Initial Title', 48.8, 2.3, 'Initial desc', 'Address 1');
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Revision 1 (initial)
        $this->client->loginUser($creator);
        $csrf = $this->fetchCsrfToken();
        $revService = static::getContainer()->get(\App\Service\PoiVersioningService::class);
        \assert($revService instanceof \App\Service\PoiVersioningService);
        $revService->recordRevision($poi, $creator, ['initial' => true, 'snapshot' => $revService->buildSnapshot($poi)]);
        $this->em->flush();

        // Update -> Revision 2
        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'version' => 1,
                'name' => 'Modified Title',
                'description' => 'Modified desc',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // Check revision list
        $this->client->request('GET', '/api/pois/'.$poiId.'/revisions');
        self::assertResponseIsSuccessful();
        $history = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($history);
        self::assertCount(2, $history);
        self::assertSame(2, $history[0]['revisionNumber']);
        self::assertSame(1, $history[1]['revisionNumber']);

        // Non-creator attempts revert -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/revisions/1/revert',
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrfOther],
        );
        self::assertResponseStatusCodeSame(403);

        // Creator reverts to revision 1 -> creates forward revision 3 with snapshot of revision 1
        $this->client->loginUser($creator);
        $csrfCreator = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/revisions/1/revert',
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrfCreator],
        );
        self::assertResponseIsSuccessful();
        $revertedPoi = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($revertedPoi);
        self::assertSame('Initial Title', $revertedPoi['name']);
        self::assertSame('Initial desc', $revertedPoi['description']);
        self::assertSame(3, $revertedPoi['version']);

        // Reverting non-existent revision -> 404
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/revisions/999/revert',
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrfCreator],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testPoiEdgeCasesNotFoundAndInvalidIds(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();
        $unknownId = Uuid::v7()->toRfc4122();

        // Show invalid UUID
        $this->client->request('GET', '/api/pois/invalid-id');
        self::assertResponseStatusCodeSame(404);

        // Show unknown UUID
        $this->client->request('GET', '/api/pois/'.$unknownId);
        self::assertResponseStatusCodeSame(404);

        // Update invalid UUID
        $this->client->request('PATCH', '/api/pois/invalid-id', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['version' => 1], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Update unknown UUID
        $this->client->request('PATCH', '/api/pois/'.$unknownId, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['version' => 1], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Delete invalid UUID
        $this->client->request('DELETE', '/api/pois/invalid-id', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        // Delete unknown UUID
        $this->client->request('DELETE', '/api/pois/'.$unknownId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        // Visibility invalid UUID
        $this->client->request('PATCH', '/api/pois/invalid-id/visibility', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['isPublic' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Visibility unknown UUID
        $this->client->request('PATCH', '/api/pois/'.$unknownId.'/visibility', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['isPublic' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        // Revisions invalid UUID
        $this->client->request('GET', '/api/pois/invalid-id/revisions');
        self::assertResponseStatusCodeSame(404);

        // Revisions unknown UUID
        $this->client->request('GET', '/api/pois/'.$unknownId.'/revisions');
        self::assertResponseStatusCodeSame(404);

        // Revert invalid UUID
        $this->client->request('POST', '/api/pois/invalid-id/revisions/1/revert', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        // Revert unknown UUID
        $this->client->request('POST', '/api/pois/'.$unknownId.'/revisions/1/revert', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateCoordinatesAndAddress(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);

        $poi = new Poi($user, 'Original Cafe', 48.85, 2.35, 'Desc', 'Original Address');
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'PATCH',
            '/api/pois/'.$poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'version' => 1,
                'address' => 'Updated Street 123',
                'latitude' => 48.8600,
                'longitude' => 2.3600,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Updated Street 123', $data['address']);
        self::assertSame(48.8600, $data['latitude']);
        self::assertSame(2.3600, $data['longitude']);
    }

    private function fetchCsrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        self::assertResponseStatusCodeSame(204);

        $cookie = $this->client->getCookieJar()->get('XSRF-TOKEN');
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie->getValue();
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
