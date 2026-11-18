<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\Poi;
use App\Entity\Reaction;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite covering POI comments and emoji reactions:
 * creation, toggling, aggregation, visibility filtering, and deletion permissions.
 */
final class CommentReactionTest extends WebTestCase
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

    public function testCommentLifecycleAndPermissions(): void
    {
        $author = new User('author@yummy.test', 'Author');
        $other = new User('other@yummy.test', 'Other');
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($author);
        $this->em->persist($other);
        $this->em->persist($admin);

        $poi = new Poi($author, 'Bakery', 48.85, 2.35, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Author posts comment (defaults isPublic = false)
        $this->client->loginUser($author);
        $csrfAuthor = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/comments',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAuthor],
            json_encode(['body' => 'Try the pistachio croissant!'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($created);
        self::assertSame('Try the pistachio croissant!', $created['body']);
        self::assertSame('Author', $created['authorName']);
        self::assertFalse($created['isPublic']);

        $commentId = $created['id'];

        // Other user cannot toggle visibility -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/comments/'.$commentId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfOther],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        // Author toggles visibility to public -> 200
        $this->client->loginUser($author);
        $csrfAuthor = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/comments/'.$commentId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAuthor],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // Other user cannot delete -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request('DELETE', '/api/comments/'.$commentId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOther]);
        self::assertResponseStatusCodeSame(403);

        // Admin can delete -> 204
        $this->client->loginUser($admin);
        $csrfAdmin = $this->fetchCsrfToken();
        $this->client->request('DELETE', '/api/comments/'.$commentId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfAdmin]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Comment::class)->find(Uuid::fromString($commentId)));
    }

    public function testToggleReactionsAndGuestVisibility(): void
    {
        $alice = new User('alice@yummy.test', 'Alice');
        $bob = new User('bob@yummy.test', 'Bob');
        $this->em->persist($alice);
        $this->em->persist($bob);

        $poi = new Poi($alice, 'Pizza Place', 48.85, 2.35, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Alice toggles public 🍕 reaction ON
        $this->client->loginUser($alice);
        $csrfAlice = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode(['emoji' => '🍕', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $res1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($res1);
        self::assertTrue($res1['reacted']);
        self::assertCount(1, $res1['reactions']);
        self::assertSame(1, $res1['reactions'][0]['count']);
        self::assertTrue($res1['reactions'][0]['reactedByMe']);

        // Bob toggles private 🍕 reaction ON (count = 2 for members, 1 for guests)
        $this->client->loginUser($bob);
        $csrfBob = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode(['emoji' => '🍕', 'isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $res2 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($res2);
        self::assertTrue($res2['reacted']);
        self::assertSame(2, $res2['reactions'][0]['count']);
        self::assertTrue($res2['reactions'][0]['reactedByMe']);

        // Guest views POI detail -> only sees Alice's public reaction (count = 1, reactedByMe = false)
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/pois/'.$poiId);
        self::assertResponseIsSuccessful();
        $guestDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($guestDetail);
        self::assertCount(1, $guestDetail['reactions']);
        self::assertSame(1, $guestDetail['reactions'][0]['count']);
        self::assertFalse($guestDetail['reactions'][0]['reactedByMe']);

        // Alice toggles 🍕 reaction OFF
        $this->client->loginUser($alice);
        $csrfAlice = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode(['emoji' => '🍕'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $res3 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($res3);
        self::assertFalse($res3['reacted']);
        self::assertSame(1, $res3['reactions'][0]['count']);
        self::assertFalse($res3['reactions'][0]['reactedByMe']);
    }

    public function testCommentAndReactionNotFoundIds(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();
        $unknown = Uuid::v7()->toRfc4122();

        $this->client->request('POST', '/api/pois/bad-id/comments', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['body' => 'Hi'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/pois/'.$unknown.'/comments', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['body' => 'Hi'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('PATCH', '/api/comments/bad-id/visibility', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['isPublic' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/comments/'.$unknown, [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/pois/bad-id/reactions', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['emoji' => '🔥'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/reviews/bad-id/reactions', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['emoji' => '🔥'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/reviews/'.$unknown.'/reactions', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['emoji' => '🔥'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/photos/bad-id/reactions', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['emoji' => '🔥'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/photos/'.$unknown.'/reactions', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf], json_encode(['emoji' => '🔥'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testToggleReviewAndPhotoReactionsWithGuestVisibilityAndCascade(): void
    {
        $alice = new User('alice@yummy.test', 'Alice');
        $bob = new User('bob@yummy.test', 'Bob');
        $this->em->persist($alice);
        $this->em->persist($bob);

        $poi = new Poi($alice, 'Ramen Bar', 35.67, 139.65, null, null, true);
        $this->em->persist($poi);

        $review = new Review($poi, $alice, 5, 'Rich tonkotsu broth', true);
        $this->em->persist($review);

        $photo = new Photo($poi, $alice, 'ramen.jpg', 'image/jpeg', 1200, 900, $review, 'Bowl', true);
        $this->em->persist($photo);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();
        $reviewId = $review->getId()->toRfc4122();
        $photoId = $photo->getId()->toRfc4122();

        // Alice toggles public ❤️ on Review and 📸 on Photo
        $this->client->loginUser($alice);
        $csrfAlice = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/reviews/'.$reviewId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode(['emoji' => '❤️', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $revRes1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($revRes1);
        self::assertTrue($revRes1['reacted']);
        self::assertCount(1, $revRes1['reactions']);
        self::assertSame(1, $revRes1['reactions'][0]['count']);
        self::assertTrue($revRes1['reactions'][0]['reactedByMe']);

        $this->client->request(
            'POST',
            '/api/photos/'.$photoId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode(['emoji' => '📸', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $photoRes1 = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($photoRes1);
        self::assertTrue($photoRes1['reacted']);
        self::assertSame(1, $photoRes1['reactions'][0]['count']);

        // Bob toggles private ❤️ on Review and private 📸 on Photo
        $this->client->loginUser($bob);
        $csrfBob = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/reviews/'.$reviewId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode(['emoji' => '❤️', 'isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/api/photos/'.$photoId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfBob],
            json_encode(['emoji' => '📸', 'isPublic' => false], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        // Authenticated member sees count = 2 and reactedByMe = true on both targets in PoiDetail
        $this->client->request('GET', '/api/pois/'.$poiId);
        self::assertResponseIsSuccessful();
        $memberDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($memberDetail);
        self::assertSame(2, $memberDetail['reviews'][0]['reactions'][0]['count']);
        self::assertTrue($memberDetail['reviews'][0]['reactions'][0]['reactedByMe']);
        self::assertSame(2, $memberDetail['photos'][0]['reactions'][0]['count']);
        self::assertTrue($memberDetail['photos'][0]['reactions'][0]['reactedByMe']);

        // Guest only sees public reactions (count = 1, reactedByMe = false)
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/pois/'.$poiId);
        self::assertResponseIsSuccessful();
        $guestDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($guestDetail);
        self::assertSame(1, $guestDetail['reviews'][0]['reactions'][0]['count']);
        self::assertFalse($guestDetail['reviews'][0]['reactions'][0]['reactedByMe']);
        self::assertSame(1, $guestDetail['photos'][0]['reactions'][0]['count']);
        self::assertFalse($guestDetail['photos'][0]['reactions'][0]['reactedByMe']);

        // Verify DB rows have accurate targetType and targetId
        $this->em->clear();
        $allReactions = $this->em->getRepository(Reaction::class)->findAll();
        self::assertCount(4, $allReactions);
        $reviewReactions = $this->em->getRepository(Reaction::class)->findBy(['targetType' => Reaction::TARGET_REVIEW]);
        $photoReactions = $this->em->getRepository(Reaction::class)->findBy(['targetType' => Reaction::TARGET_PHOTO]);
        self::assertCount(2, $reviewReactions);
        self::assertCount(2, $photoReactions);

        // Alice toggles ❤️ OFF on Review -> count drops to 1
        $this->client->loginUser($alice);
        $csrfAlice = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/reviews/'.$reviewId.'/reactions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAlice],
            json_encode(['emoji' => '❤️'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $revToggleOff = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($revToggleOff);
        self::assertFalse($revToggleOff['reacted']);
        self::assertSame(1, $revToggleOff['reactions'][0]['count']);
        self::assertFalse($revToggleOff['reactions'][0]['reactedByMe']);

        // Deleting the photo cascades and removes its 2 reactions from DB
        $this->client->request('DELETE', '/api/photos/'.$photoId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfAlice]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Reaction::class)->findBy(['targetType' => Reaction::TARGET_PHOTO]));
        self::assertCount(1, $this->em->getRepository(Reaction::class)->findBy(['targetType' => Reaction::TARGET_REVIEW]));
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
        $conn->executeStatement('TRUNCATE TABLE photos');
        $conn->executeStatement('TRUNCATE TABLE reactions');
        $conn->executeStatement('TRUNCATE TABLE comments');
        $conn->executeStatement('TRUNCATE TABLE reviews');
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
