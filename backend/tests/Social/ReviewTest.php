<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Entity\Photo;
use App\Entity\Poi;
use App\Entity\Review;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite covering POI reviews: multiple reviews per user,
 * validation, visibility toggling, deletion permissions, and guest filtering.
 */
final class ReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    public function testCreateMultipleReviewsBySameUserOnSamePoi(): void
    {
        $author = new User('foodie@yummy.test', 'Foodie');
        $this->em->persist($author);

        $poi = new Poi($author, 'Bistro Lumiere', 48.8566, 2.3522, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        $this->client->loginUser($author);
        $csrf = $this->fetchCsrfToken();

        // First visit review
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 4, 'body' => 'First visit: great soup!'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($first);
        self::assertSame(4, $first['rating']);
        self::assertSame('Foodie', $first['authorName']);
        self::assertFalse($first['isPublic']);

        // Second visit review by the SAME user on the SAME POI
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 5, 'body' => 'Second visit: duck confit was 10/10.', 'isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $second = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($second);
        self::assertSame(5, $second['rating']);
        self::assertTrue($second['isPublic']);
        self::assertNotSame($first['id'], $second['id']);

        $persisted = $this->em->getRepository(Review::class)->findBy(['poi' => $poi]);
        self::assertCount(2, $persisted);
    }

    public function testCreateReviewValidationAndEdgeCases(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);
        $poi = new Poi($user, 'Taco Spot', 40.7128, -74.0060);
        $this->em->persist($poi);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Unauthenticated -> 401
        $csrfGuest = $this->fetchCsrfToken();
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfGuest],
            json_encode(['rating' => 5], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        // Out of range rating -> 422
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 6], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);

        // Invalid POI UUID -> 404
        $this->client->request(
            'POST',
            '/api/pois/not-a-uuid/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 5], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);

        // Unknown POI UUID -> 404
        $this->client->request(
            'POST',
            '/api/pois/'.Uuid::v7()->toRfc4122().'/reviews',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf],
            json_encode(['rating' => 5], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testReviewVisibilityToggleAndDeletePermissions(): void
    {
        $author = new User('author@yummy.test', 'Author');
        $other = new User('other@yummy.test', 'Other');
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($author);
        $this->em->persist($other);
        $this->em->persist($admin);

        $poi = new Poi($author, 'Cafe', 48.0, 2.0, null, null, true);
        $this->em->persist($poi);

        $review = new Review($poi, $author, 4, 'Nice coffee', false);
        $this->em->persist($review);

        $photo = new Photo($poi, $author, 'key123', 'image/jpeg', 800, 600, $review, 'Latte art', false);
        $this->em->persist($photo);
        $this->em->flush();

        $reviewId = $review->getId()->toRfc4122();
        $photoId = $photo->getId()->toRfc4122();

        // Other user cannot toggle visibility -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/reviews/'.$reviewId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfOther],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(403);

        // Author can toggle visibility -> 200
        $this->client->loginUser($author);
        $csrfAuthor = $this->fetchCsrfToken();
        $this->client->request(
            'PATCH',
            '/api/reviews/'.$reviewId.'/visibility',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfAuthor],
            json_encode(['isPublic' => true], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($updated);
        self::assertTrue($updated['isPublic']);

        // Other user cannot delete -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request('DELETE', '/api/reviews/'.$reviewId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOther]);
        self::assertResponseStatusCodeSame(403);

        // Admin can delete -> 204, and attached photo stays in gallery with reviewId = null
        $this->client->loginUser($admin);
        $csrfAdmin = $this->fetchCsrfToken();
        $this->client->request('DELETE', '/api/reviews/'.$reviewId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfAdmin]);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Review::class)->find(Uuid::fromString($reviewId)));
        $orphanedPhoto = $this->em->getRepository(Photo::class)->find(Uuid::fromString($photoId));
        self::assertInstanceOf(Photo::class, $orphanedPhoto);
        self::assertNull($orphanedPhoto->getReview());
    }

    public function testGuestVisibilityFilteringOnPoiDetail(): void
    {
        $author = new User('author@yummy.test', 'Author');
        $this->em->persist($author);

        $poi = new Poi($author, 'Public Spot', 48.0, 2.0, null, null, true);
        $this->em->persist($poi);

        $privateReview = new Review($poi, $author, 2, 'Private note', false);
        $publicReview = new Review($poi, $author, 5, 'Public praise', true);
        $this->em->persist($privateReview);
        $this->em->persist($publicReview);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();

        // Guest only sees public review
        $this->client->request('GET', '/api/pois/'.$poiId);
        self::assertResponseIsSuccessful();
        $guestDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($guestDetail);
        self::assertCount(1, $guestDetail['reviews']);
        self::assertSame('Public praise', $guestDetail['reviews'][0]['body']);

        // Authenticated member sees both reviews
        $this->client->loginUser($author);
        $this->client->request('GET', '/api/pois/'.$poiId);
        self::assertResponseIsSuccessful();
        $memberDetail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($memberDetail);
        self::assertCount(2, $memberDetail['reviews']);
    }

    private function fetchCsrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        self::assertResponseStatusCodeSame(204);

        $cookie = $this->client->getCookieJar()->get('XSRF-TOKEN');
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie->getValue();
    }
}
