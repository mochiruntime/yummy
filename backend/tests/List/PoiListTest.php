<?php

declare(strict_types=1);

namespace App\Tests\List;

use App\Entity\Poi;
use App\Entity\PoiList;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite for collaborative POI lists/categories.
 */
final class PoiListTest extends WebTestCase
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

    public function testListRequiresAuthForCreation(): void
    {
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/lists',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['name' => 'Pizza Places'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateListAndAutoGenerateSlug(): void
    {
        $user = new User('owner@yummy.test', 'List Owner');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/lists',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'name' => 'Best Ramen in Town!',
                'color' => '#ff5722',
                'icon' => 'ramen',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Best Ramen in Town!', $data['name']);
        self::assertSame('best-ramen-in-town', $data['slug']);
        self::assertSame('#ff5722', $data['color']);
        self::assertSame('ramen', $data['icon']);
        self::assertSame($user->getId()->toRfc4122(), $data['ownerId']);
        self::assertSame('List Owner', $data['ownerName']);
    }

    public function testSlugDisambiguationForSameOwner(): void
    {
        $user = new User('owner@yummy.test', 'List Owner');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        for ($i = 1; $i <= 3; ++$i) {
            $this->client->request(
                'POST',
                '/api/lists',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
                json_encode(['name' => 'Burgers'], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(201);
        }

        $lists = $this->em->getRepository(PoiList::class)->findBy(['owner' => $user], ['createdAt' => 'ASC']);
        self::assertCount(3, $lists);
        self::assertSame('burgers', $lists[0]->getSlug());
        self::assertSame('burgers-2', $lists[1]->getSlug());
        self::assertSame('burgers-3', $lists[2]->getSlug());
    }

    public function testGetListsOrderedByName(): void
    {
        $user = new User('owner@yummy.test', 'List Owner');
        $this->em->persist($user);

        $listZ = new PoiList($user, 'Zebra Cafe', 'zebra-cafe', '#333333', 'coffee');
        $listA = new PoiList($user, 'Apple Bakery', 'apple-bakery', '#ff0000', 'bakery');
        $this->em->persist($listZ);
        $this->em->persist($listA);
        $this->em->flush();

        $this->client->request('GET', '/api/lists');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertSame('Apple Bakery', $data[0]['name']);
        self::assertSame('Zebra Cafe', $data[1]['name']);
    }

    public function testUpdateListOnlyOwnerOrAdmin(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $other = new User('other@yummy.test', 'Other');
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($owner);
        $this->em->persist($other);
        $this->em->persist($admin);

        $list = new PoiList($owner, 'Original Name', 'original-name', '#000000', 'pin');
        $this->em->persist($list);
        $this->em->flush();

        $listId = $list->getId()->toRfc4122();

        // Other user attempts update -> 403
        $this->client->loginUser($other);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/lists/'.$listId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['name' => 'Hacked Name'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        // Owner updates -> 200
        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/lists/'.$listId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'name' => 'Updated Name',
                'color' => '#123456',
                'icon' => 'star',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertSame('Updated Name', $updated['name']);
        self::assertSame('updated-name', $updated['slug']);
        self::assertSame('#123456', $updated['color']);
        self::assertSame('star', $updated['icon']);

        // Admin updates -> 200
        $this->client->loginUser($admin);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/lists/'.$listId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['name' => 'Admin Updated Name'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    public function testDeleteListOnlyOwnerOrAdmin(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($owner);
        $this->em->persist($other);

        $list = new PoiList($owner, 'To Delete', 'to-delete', '#000000', 'pin');
        $this->em->persist($list);
        $this->em->flush();

        $listId = $list->getId()->toRfc4122();

        // Other attempts delete -> 403
        $this->client->loginUser($other);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'DELETE',
            '/api/lists/'.$listId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        // Owner deletes -> 204
        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'DELETE',
            '/api/lists/'.$listId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(PoiList::class)->find(Uuid::fromString($listId)));
    }

    public function testAddAndRemovePoiFromList(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $this->em->persist($owner);

        $list = new PoiList($owner, 'Desserts', 'desserts', '#ff00aa', 'ice-cream');
        $poi = new Poi($owner, 'Gelato Shop', 48.8584, 2.2945, 'Great pistachio gelato', '1 Rue de Paris');
        $this->em->persist($list);
        $this->em->persist($poi);
        $this->em->flush();

        $listId = $list->getId()->toRfc4122();
        $poiId = $poi->getId()->toRfc4122();

        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();

        // Add POI to list
        $this->client->request(
            'POST',
            '/api/lists/'.$listId.'/pois/'.$poiId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $refreshedPoi = $this->em->getRepository(Poi::class)->find(Uuid::fromString($poiId));
        self::assertNotNull($refreshedPoi);
        self::assertCount(1, $refreshedPoi->getLists());

        // Remove POI from list
        $this->client->request(
            'DELETE',
            '/api/lists/'.$listId.'/pois/'.$poiId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $refreshedPoi2 = $this->em->getRepository(Poi::class)->find(Uuid::fromString($poiId));
        self::assertNotNull($refreshedPoi2);
        self::assertCount(0, $refreshedPoi2->getLists());
    }

    public function testListNotFoundReturns404(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();
        $unknownId = Uuid::v7()->toRfc4122();

        // Invalid UUID on update
        $this->client->request(
            'PATCH',
            '/api/lists/invalid-uuid',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['name' => 'Bad ID'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);

        // Not found on update
        $this->client->request(
            'PATCH',
            '/api/lists/'.$unknownId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['name' => 'Does not exist'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);

        // Invalid UUID on delete
        $this->client->request(
            'DELETE',
            '/api/lists/invalid-uuid',
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(404);

        // Not found on delete
        $this->client->request(
            'DELETE',
            '/api/lists/'.$unknownId,
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testAddAndRemovePoiEdgeCases(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($owner);
        $this->em->persist($other);

        $list = new PoiList($owner, 'Desserts', 'desserts', '#ff00aa', 'ice-cream');
        $poi = new Poi($owner, 'Gelato Shop', 48.8584, 2.2945, 'Great pistachio gelato', '1 Rue de Paris');
        $this->em->persist($list);
        $this->em->persist($poi);
        $this->em->flush();

        $listId = $list->getId()->toRfc4122();
        $poiId = $poi->getId()->toRfc4122();
        $unknownId = Uuid::v7()->toRfc4122();

        // Other user attempts to add POI to list -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request('POST', '/api/lists/'.$listId.'/pois/'.$poiId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOther]);
        self::assertResponseStatusCodeSame(403);

        // Other user attempts to remove POI from list -> 403
        $this->client->request('DELETE', '/api/lists/'.$listId.'/pois/'.$poiId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOther]);
        self::assertResponseStatusCodeSame(403);

        // Owner with invalid UUID
        $this->client->loginUser($owner);
        $csrfOwner = $this->fetchCsrfToken();
        $this->client->request('POST', '/api/lists/invalid/pois/'.$poiId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOwner]);
        self::assertResponseStatusCodeSame(404);

        // Owner with non-existent POI
        $this->client->request('POST', '/api/lists/'.$listId.'/pois/'.$unknownId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOwner]);
        self::assertResponseStatusCodeSame(404);

        // Owner remove non-existent
        $this->client->request('DELETE', '/api/lists/'.$listId.'/pois/'.$unknownId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOwner]);
        self::assertResponseStatusCodeSame(404);
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
