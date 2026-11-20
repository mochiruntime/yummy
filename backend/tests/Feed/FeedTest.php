<?php

declare(strict_types=1);

namespace App\Tests\Feed;

use App\Entity\ActivityEvent;
use App\Entity\Poi;
use App\Entity\User;
use App\Repository\ActivityEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional test suite covering the Activity Feed API (GET /api/feed):
 * event emission across all 6 event types, guest/member visibility invariants,
 * parent review/photo visibility enforcement, deletion cleanup, and cursor pagination.
 */
final class FeedTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ActivityEventRepository $events;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $repo = static::getContainer()->get(ActivityEventRepository::class);
        \assert($repo instanceof ActivityEventRepository);
        $this->events = $repo;
    }

    public function testAllEventTypesEmittedAndVisibleToMembers(): void
    {
        $alice = new User('alice@yummy.test', 'Alice');
        $bob = new User('bob@yummy.test', 'Bob');
        $this->em->persist($alice);
        $this->em->persist($bob);
        $this->em->flush();

        $this->client->loginUser($alice);
        $csrfAlice = $this->fetchCsrfToken();

        // 1. poi.created
        $this->client->request(
            'POST',
            '/api/pois',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode([
                'name' => 'Pastéis de Belém',
                'latitude' => 38.6975,
                'longitude' => -9.2033,
                'isPublic' => true,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $poiData = $this->decodeJson();
        $poiId = $poiData['id'];
        self::assertIsString($poiId);

        // 2. poi.edited (update)
        $this->client->request(
            'PATCH',
            '/api/pois/' . $poiId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode([
                'version' => $poiData['version'],
                'description' => 'Famous custard tarts',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        // 3. poi.edited (revert)
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/revisions/1/revert',
            [],
            [],
            ['HTTP_X_XSRF_TOKEN' => $csrfAlice],
        );
        self::assertResponseStatusCodeSame(200);

        // 4. review.added (by Bob, private)
        $this->client->loginUser($bob);
        $csrfBob = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode([
                'rating' => 5,
                'body' => 'Warm and crispy!',
                'isPublic' => false,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        // 5. comment.added (public)
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/comments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode([
                'body' => 'Go early to skip the line.',
                'isPublic' => true,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        // 6. photo.added (public)
        $tmpPng = $this->createTempPng();
        try {
            $uploaded = new UploadedFile($tmpPng, 'tart.png', 'image/png', null, true);
            $this->client->request(
                'POST',
                '/api/pois/' . $poiId . '/photos',
                ['isPublic' => '1', 'caption' => 'Fresh out of the oven'],
                ['file' => $uploaded],
                ['HTTP_X_XSRF_TOKEN' => $csrfBob],
            );
            self::assertResponseStatusCodeSame(201);
        } finally {
            @unlink($tmpPng);
        }

        // 7. reaction.added (public, on POI)
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode(['emoji' => '🥧', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        // Authenticated member sees all 7 events
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $memberFeed = $this->decodeJson();
        $memberItems = $memberFeed['items'];
        self::assertIsArray($memberItems);
        self::assertCount(7, $memberItems);
        self::assertNull($memberFeed['nextCursor']);

        $eventTypes = array_column($memberItems, 'eventType');
        self::assertContains(ActivityEvent::EVENT_POI_CREATED, $eventTypes);
        self::assertContains(ActivityEvent::EVENT_POI_EDITED, $eventTypes);
        self::assertContains(ActivityEvent::EVENT_REVIEW_ADDED, $eventTypes);
        self::assertContains(ActivityEvent::EVENT_COMMENT_ADDED, $eventTypes);
        self::assertContains(ActivityEvent::EVENT_PHOTO_ADDED, $eventTypes);
        self::assertContains(ActivityEvent::EVENT_REACTION_ADDED, $eventTypes);

        // Verify OpenAPI item fields & aggregated reactions
        $first = $memberItems[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('actorName', $first);
        self::assertSame($poiId, $first['poiId']);
        self::assertSame('Pastéis de Belém', $first['poiName']);
        self::assertArrayHasKey('createdAt', $first);
        self::assertSame([['emoji' => '🥧', 'count' => 1, 'reactedByMe' => true]], $first['reactions']);

        // Guest sees 6 public events (excludes private review.added) and reactedByMe is false
        $this->client->restart();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $guestFeed = $this->decodeJson();
        $guestItems = $guestFeed['items'];
        self::assertIsArray($guestItems);
        self::assertCount(6, $guestItems);
        self::assertNotContains(ActivityEvent::EVENT_REVIEW_ADDED, array_column($guestItems, 'eventType'));
        $guestFirst = $guestItems[0];
        self::assertIsArray($guestFirst);
        self::assertSame([['emoji' => '🥧', 'count' => 1, 'reactedByMe' => false]], $guestFirst['reactions']);
    }

    public function testGuestVisibilitySyncAndParentPrivacyInvariants(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $this->em->persist($owner);
        $this->em->flush();

        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();

        // Create a PRIVATE POI
        $this->client->request(
            'POST',
            '/api/pois',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode([
                'name' => 'Secret Ramen',
                'latitude' => 35.68,
                'longitude' => 139.76,
                'isPublic' => false,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $poiId = $this->decodeJson()['id'];
        self::assertIsString($poiId);

        // Add a PUBLIC comment on the PRIVATE POI
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/comments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['body' => 'Public comment on private POI', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $commentId = $this->decodeJson()['id'];
        self::assertIsString($commentId);

        // Add a PRIVATE review + PUBLIC photo attached to that PRIVATE review
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 5, 'body' => 'Private review', 'isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $reviewId = $this->decodeJson()['id'];
        self::assertIsString($reviewId);

        $tmpPng = $this->createTempPng();
        try {
            $uploaded = new UploadedFile($tmpPng, 'ramen.png', 'image/png', null, true);
            $this->client->request(
                'POST',
                '/api/pois/' . $poiId . '/photos',
                ['isPublic' => '1', 'reviewId' => $reviewId],
                ['file' => $uploaded],
                ['HTTP_X_XSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(201);
            $photoId = $this->decodeJson()['id'];
            self::assertIsString($photoId);
        } finally {
            @unlink($tmpPng);
        }

        // Guest sees 0 events because parent POI is private
        $this->client->restart();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $guestItems = $this->decodeJson()['items'];
        self::assertIsArray($guestItems);
        self::assertCount(0, $guestItems);

        // Owner makes POI public -> Guest sees poi.created + comment.added,
        // but NOT review.added (private) and NOT photo.added (attached to private review)
        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/pois/' . $poiId . '/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->restart();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $guestItems = $this->decodeJson()['items'];
        self::assertIsArray($guestItems);
        self::assertCount(2, $guestItems);
        self::assertSame(
            [ActivityEvent::EVENT_COMMENT_ADDED, ActivityEvent::EVENT_POI_CREATED],
            array_column($guestItems, 'eventType'),
        );

        // Owner makes review public -> Guest now sees review.added AND photo.added (4 events total)
        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/reviews/' . $reviewId . '/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->restart();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $guestItems = $this->decodeJson()['items'];
        self::assertIsArray($guestItems);
        self::assertCount(4, $guestItems);

        // Owner toggles comment and photo back to private -> Guest sees only poi.created + review.added (2 events)
        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/comments/' . $commentId . '/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->request(
            'PATCH',
            '/api/photos/' . $photoId . '/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->restart();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        $guestItems = $this->decodeJson()['items'];
        self::assertIsArray($guestItems);
        self::assertCount(2, $guestItems);
    }

    public function testDeletingTargetsAndTogglingOffReactionsRemovesFeedEvents(): void
    {
        $user = new User('cleanup@yummy.test', 'Cleanup');
        $this->em->persist($user);
        $poi = new Poi($user, 'Cafe', 40.0, -3.0, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();
        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        // Create review, photo, comment, and reactions on POI, review, and photo
        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 4, 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        $reviewId = $this->decodeJson()['id'];
        self::assertIsString($reviewId);

        $tmpPng = $this->createTempPng();
        try {
            $this->client->request(
                'POST',
                '/api/pois/' . $poiId . '/photos',
                ['isPublic' => '1'],
                ['file' => new UploadedFile($tmpPng, 'cafe.png', 'image/png', null, true)],
                ['HTTP_X_XSRF_TOKEN' => $csrf],
            );
            self::assertResponseStatusCodeSame(201);
            $photoId = $this->decodeJson()['id'];
            self::assertIsString($photoId);
        } finally {
            @unlink($tmpPng);
        }

        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/comments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['body' => 'Nice espresso', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        $commentId = $this->decodeJson()['id'];
        self::assertIsString($commentId);

        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['emoji' => '☕', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->request(
            'POST',
            '/api/reviews/' . $reviewId . '/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['emoji' => '👍', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->request(
            'POST',
            '/api/photos/' . $photoId . '/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['emoji' => '📸', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        self::assertCount(6, $this->events->findAll());

        // Delete review (and its cascaded reaction), photo (and its cascaded reaction), comment, and toggle off POI reaction
        $this->client->request('DELETE', '/api/reviews/' . $reviewId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/photos/' . $photoId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/comments/' . $commentId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request(
            'POST',
            '/api/pois/' . $poiId . '/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['emoji' => '☕', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        self::assertCount(0, $this->events->findAll());

        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodeJson()['items']);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/feed');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodeJson()['items']);
    }

    public function testCursorPaginationAndValidationErrors(): void
    {
        $user = new User('pager@yummy.test', 'Pager');
        $this->em->persist($user);
        $poi = new Poi($user, 'Bakery', 48.8, 2.3, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();
        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        for ($i = 1; $i <= 3; ++$i) {
            $this->client->request(
                'POST',
                '/api/pois/' . $poiId . '/comments',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
                json_encode(['body' => 'Comment ' . $i, 'isPublic' => true], JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(201);
        }

        // Page 1: limit=2
        $this->client->request('GET', '/api/feed?limit=2');
        self::assertResponseStatusCodeSame(200);
        $page1 = $this->decodeJson();
        $items1 = $page1['items'];
        $nextCursor = $page1['nextCursor'];
        self::assertIsArray($items1);
        self::assertCount(2, $items1);
        self::assertIsString($nextCursor);

        // Page 2: using nextCursor
        $this->client->request('GET', '/api/feed?limit=2&cursor=' . urlencode($nextCursor));
        self::assertResponseStatusCodeSame(200);
        $page2 = $this->decodeJson();
        $items2 = $page2['items'];
        self::assertIsArray($items2);
        self::assertCount(1, $items2);
        self::assertNull($page2['nextCursor']);

        // Ensure no overlap between pages
        $ids1 = array_column($items1, 'id');
        $ids2 = array_column($items2, 'id');
        self::assertSame([], array_intersect($ids1, $ids2));

        // Invalid cursor -> 400
        $this->client->request('GET', '/api/feed?cursor=not-a-valid-cursor');
        self::assertResponseStatusCodeSame(400);

        // Out-of-range limit -> 400
        $this->client->request('GET', '/api/feed?limit=101');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function fetchCsrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        self::assertResponseStatusCodeSame(204);

        $cookie = $this->client->getCookieJar()->get('XSRF-TOKEN');
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie->getValue();
    }

    private function createTempPng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yummy_feed_') . '.png';
        $img = imagecreatetruecolor(16, 16);
        \assert($img !== false);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
