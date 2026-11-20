<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Entity\Photo;
use App\Entity\Poi;
use App\Entity\Review;
use App\Entity\ReviewItem;
use App\Entity\User;
use App\Service\ImageProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Functional test suite covering photo uploads, server-side EXIF/GPS stripping,
 * thumbnail generation, unified gallery ordering, parent-child visibility matrix,
 * and secure file streaming.
 */
final class PhotoTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ImageProcessingService $imageService;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $imageService = static::getContainer()->get(ImageProcessingService::class);
        \assert($imageService instanceof ImageProcessingService);
        $this->imageService = $imageService;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testUploadStripsExifGpsAndCreatesVariants(): void
    {
        $uploader = new User('uploader@yummy.test', 'Photographer');
        $this->em->persist($uploader);

        $poi = new Poi($uploader, 'Ramen House', 35.6762, 139.6503, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $jpegWithExif = $this->createSyntheticJpegWithExif(2400, 1200);
        $uploaded = new UploadedFile($jpegWithExif, 'ramen.jpg', 'image/jpeg', null, true);

        $this->client->loginUser($uploader);
        $csrf = $this->fetchCsrfToken();

        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['caption' => 'Rich tonkotsu broth', 'isPublic' => 'true'],
            ['file' => $uploaded],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('Rich tonkotsu broth', $data['caption']);
        self::assertTrue($data['isPublic']);
        self::assertNull($data['reviewId']);
        self::assertStringContainsString('/file?size=full', $data['url']);
        self::assertStringContainsString('/file?size=thumb', $data['thumbUrl']);

        $photo = $this->em->getRepository(Photo::class)->find(Uuid::fromString($data['id']));
        self::assertInstanceOf(Photo::class, $photo);

        // Full variant must be scaled down to <= 2048px and stripped of EXIF/GPS
        self::assertSame(2048, $photo->getWidth());
        self::assertSame(1024, $photo->getHeight());

        $fullPath = $this->imageService->resolveFilePath($photo, 'full');
        $thumbPath = $this->imageService->resolveFilePath($photo, 'thumb');
        self::assertFileExists($fullPath);
        self::assertFileExists($thumbPath);

        $fullImg = new \Imagick($fullPath);
        self::assertSame([], $fullImg->getImageProperties('exif:*'), 'All EXIF/GPS properties must be stripped.');
        $fullImg->clear();

        $thumbImg = new \Imagick($thumbPath);
        self::assertSame(400, $thumbImg->getImageWidth());
        self::assertSame(200, $thumbImg->getImageHeight());
        self::assertSame([], $thumbImg->getImageProperties('exif:*'));
        $thumbImg->clear();

        // Clean up stored variants
        $this->imageService->deleteFiles($photo);
    }

    public function testUnifiedGalleryOrderingDirectPhotosFirst(): void
    {
        $user = new User('user@yummy.test', 'User');
        $this->em->persist($user);

        $poi = new Poi($user, 'Gelato Shop', 41.9, 12.5, null, null, true);
        $this->em->persist($poi);

        $review = new Review($poi, $user, 5, 'Amazing pistachio!', true);
        $this->em->persist($review);
        $this->em->flush();

        $this->client->loginUser($user);
        $csrf = $this->fetchCsrfToken();

        // Upload review-attached photo FIRST
        $file1 = new UploadedFile($this->createSyntheticJpegWithExif(800, 600), 'review_photo.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['caption' => 'Review Meal', 'reviewId' => $review->getId()->toRfc4122(), 'isPublic' => 'true'],
            ['file' => $file1],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        // Upload direct POI photo SECOND
        $file2 = new UploadedFile($this->createSyntheticJpegWithExif(800, 600), 'direct_photo.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['caption' => 'Storefront', 'isPublic' => 'true'],
            ['file' => $file2],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        // Inspect PoiDetail -> direct POI photo must appear BEFORE review photo
        $this->client->request('GET', '/api/pois/'.$poi->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        $detail = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($detail);
        self::assertCount(2, $detail['photos']);
        self::assertSame('Storefront', $detail['photos'][0]['caption']);
        self::assertNull($detail['photos'][0]['reviewId']);
        self::assertSame('Review Meal', $detail['photos'][1]['caption']);
        self::assertSame($review->getId()->toRfc4122(), $detail['photos'][1]['reviewId']);

        foreach ($this->em->getRepository(Photo::class)->findAll() as $p) {
            $this->imageService->deleteFiles($p);
        }
    }

    public function testParentChildVisibilityMatrixOnFileStreaming(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($owner);
        $this->em->persist($other);

        $poi = new Poi($owner, 'Secret Izakaya', 35.6, 139.7, null, null, false);
        $this->em->persist($poi);

        $review = new Review($poi, $owner, 5, 'Hidden gem', false);
        $this->em->persist($review);
        $this->em->flush();

        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();

        $file = new UploadedFile($this->createSyntheticJpegWithExif(600, 600), 'yakitori.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['reviewId' => $review->getId()->toRfc4122(), 'isPublic' => 'true'],
            ['file' => $file],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $photoData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($photoData);
        $photoId = $photoData['id'];

        // 1. POI private, Review private, Photo public -> Guest gets 404
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=full');
        self::assertResponseStatusCodeSame(404);

        // 2. POI public, Review private, Photo public -> Guest STILL gets 404
        $managedPoi = $this->em->getRepository(Poi::class)->find($poi->getId());
        \assert($managedPoi instanceof Poi);
        $managedPoi->setIsPublic(true);
        $this->em->flush();
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=full');
        self::assertResponseStatusCodeSame(404);

        // Authenticated member CAN stream private photo -> 200
        $this->client->loginUser($other);
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=thumb');
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));

        // 3. POI public, Review public, Photo private -> Guest STILL gets 404
        $this->client->getCookieJar()->clear();
        $managedReview = $this->em->getRepository(Review::class)->find($review->getId());
        \assert($managedReview instanceof Review);
        $managedReview->setIsPublic(true);
        $photoEntity = $this->em->getRepository(Photo::class)->find(Uuid::fromString($photoId));
        \assert($photoEntity instanceof Photo);
        $photoEntity->setIsPublic(false);
        $this->em->flush();
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=full');
        self::assertResponseStatusCodeSame(404);

        // 4. All 3 public -> Guest gets 200
        $photoEntity->setIsPublic(true);
        $this->em->flush();
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=full');
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));

        $this->imageService->deleteFiles($photoEntity);
    }

    public function testPhotoVisibilityDeleteAndValidationEdgeCases(): void
    {
        $owner = new User('owner@yummy.test', 'Owner');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($owner);
        $this->em->persist($other);

        $poi = new Poi($owner, 'Cafe', 48.0, 2.0, null, null, true);
        $this->em->persist($poi);
        $this->em->flush();

        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();

        // Missing file -> 400
        $this->client->request('POST', '/api/pois/'.$poi->getId()->toRfc4122().'/photos', [], [], ['HTTP_X_XSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(400);

        // Invalid MIME type (text/plain) -> 422
        $txtPath = tempnam(sys_get_temp_dir(), 'yummy_txt_');
        \assert(is_string($txtPath));
        file_put_contents($txtPath, 'not an image');
        $this->tempFiles[] = $txtPath;
        $badFile = new UploadedFile($txtPath, 'notes.txt', 'text/plain', null, true);

        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            [],
            ['file' => $badFile],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(422);

        // Pixel-flood / oversized dimensions guard rejects before full Imagick decoding
        $strictService = new ImageProcessingService(sys_get_temp_dir(), maxInputDimension: 100, maxInputPixels: 10000);
        $oversizedFile = new UploadedFile($this->createSyntheticJpegWithExif(200, 200), 'big.jpg', 'image/jpeg', null, true);
        try {
            $strictService->processAndStore($oversizedFile);
            self::fail('Expected InvalidArgumentException for oversized image dimensions.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('dimensions exceed', $e->getMessage());
        }

        // Cross-user reviewId attachment is forbidden (even for Admins) -> 403
        $managedPoi = $this->em->getRepository(Poi::class)->find($poi->getId());
        $managedOther = $this->em->getRepository(User::class)->find($other->getId());
        \assert($managedPoi instanceof Poi && $managedOther instanceof User);
        $otherReview = new Review($managedPoi, $managedOther, 4, 'Other member review', true);
        $admin = new User('admin@yummy.test', 'Admin', ['ROLE_ADMIN']);
        $this->em->persist($otherReview);
        $this->em->persist($admin);
        $this->em->flush();

        $crossUserFile = new UploadedFile($this->createSyntheticJpegWithExif(400, 400), 'cross.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['reviewId' => $otherReview->getId()->toRfc4122()],
            ['file' => $crossUserFile],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin);
        $csrfAdmin = $this->fetchCsrfToken();
        $adminCrossFile = new UploadedFile($this->createSyntheticJpegWithExif(400, 400), 'admin_cross.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['reviewId' => $otherReview->getId()->toRfc4122()],
            ['file' => $adminCrossFile],
            ['HTTP_X_XSRF_TOKEN' => $csrfAdmin],
        );
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($owner);
        $csrf = $this->fetchCsrfToken();

        // Valid upload with explicit string 'false' for isPublic -> must coerce to false
        $goodFile = new UploadedFile($this->createSyntheticJpegWithExif(500, 500), 'ok.jpg', 'image/jpeg', null, true);
        $this->client->request(
            'POST',
            '/api/pois/'.$poi->getId()->toRfc4122().'/photos',
            ['isPublic' => 'false', 'caption' => 'Private shot'],
            ['file' => $goodFile],
            ['HTTP_X_XSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($created);
        self::assertFalse($created['isPublic']);
        self::assertSame('Private shot', $created['caption']);
        $photoId = $created['id'];

        $photo = $this->em->getRepository(Photo::class)->find(Uuid::fromString($photoId));
        \assert($photo instanceof Photo);
        $fullPath = $this->imageService->resolveFilePath($photo, 'full');
        $thumbPath = $this->imageService->resolveFilePath($photo, 'thumb');

        // Invalid size param -> 400
        $this->client->request('GET', '/api/photos/'.$photoId.'/file?size=giant');
        self::assertResponseStatusCodeSame(400);

        // Other user cannot toggle visibility or delete -> 403
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $this->client->request('PATCH', '/api/photos/'.$photoId.'/visibility', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfOther], json_encode(['isPublic' => true], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('DELETE', '/api/photos/'.$photoId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOther]);
        self::assertResponseStatusCodeSame(403);

        // Owner deletes -> 204 and files removed from disk
        $this->client->loginUser($owner);
        $csrfOwner = $this->fetchCsrfToken();
        $this->client->request('DELETE', '/api/photos/'.$photoId, [], [], ['HTTP_X_XSRF_TOKEN' => $csrfOwner]);
        self::assertResponseStatusCodeSame(204);

        self::assertFileDoesNotExist($fullPath);
        self::assertFileDoesNotExist($thumbPath);
    }

    public function testUploadPhotoLinkedToReviewItemAndCrossReviewRejection(): void
    {
        $author = new User('author@yummy.test', 'Author');
        $other = new User('other@yummy.test', 'Other');
        $this->em->persist($author);
        $this->em->persist($other);

        $poi = new Poi($author, 'Ramen Shop', 48.85, 2.35, null, null, true);
        $this->em->persist($poi);

        $review1 = new Review($poi, $author, 5, 'Great!', true);
        $review2 = new Review($poi, $other, 4, 'Nice!', true);
        $item1 = new ReviewItem($review1, 'Spicy Miso Ramen', ReviewItem::VERDICT_MUST_GET, '18.50');
        $review1->addItem($item1);

        $this->em->persist($review1);
        $this->em->persist($review2);
        $this->em->persist($item1);
        $this->em->flush();

        $poiId = $poi->getId()->toRfc4122();
        $item1Id = $item1->getId()->toRfc4122();
        $review2Id = $review2->getId()->toRfc4122();

        // Other member cannot attach a photo to author's reviewItem
        $this->client->loginUser($other);
        $csrfOther = $this->fetchCsrfToken();
        $jpg1 = $this->createSyntheticJpegWithExif(120, 120);
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/photos',
            ['reviewItemId' => $item1Id],
            ['file' => new UploadedFile($jpg1, 'dish.jpg', 'image/jpeg', null, true)],
            ['HTTP_X_XSRF_TOKEN' => $csrfOther],
        );
        self::assertResponseStatusCodeSame(403);

        // Mismatched reviewId + reviewItemId returns 400
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/photos',
            ['reviewId' => $review2Id, 'reviewItemId' => $item1Id],
            ['file' => new UploadedFile($jpg1, 'dish.jpg', 'image/jpeg', null, true)],
            ['HTTP_X_XSRF_TOKEN' => $csrfOther],
        );
        self::assertResponseStatusCodeSame(400);

        // Author uploads photo linked to reviewItemId (auto-links reviewId)
        $this->client->loginUser($author);
        $csrfAuthor = $this->fetchCsrfToken();
        $jpg2 = $this->createSyntheticJpegWithExif(120, 120);
        $this->client->request(
            'POST',
            '/api/pois/'.$poiId.'/photos',
            ['reviewItemId' => $item1Id, 'isPublic' => '1'],
            ['file' => new UploadedFile($jpg2, 'dish.jpg', 'image/jpeg', null, true)],
            ['HTTP_X_XSRF_TOKEN' => $csrfAuthor],
        );
        self::assertResponseStatusCodeSame(201);
        $uploaded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($item1Id, $uploaded['reviewItemId']);
        self::assertSame($review1->getId()->toRfc4122(), $uploaded['reviewId']);
    }

    /**
     * Generates a real JPEG image with embedded EXIF/GPS metadata to verify server-side stripping.
     */
    private function createSyntheticJpegWithExif(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yummy_img_').'.jpg';
        $this->tempFiles[] = $path;

        $img = new \Imagick();
        $img->newImage($width, $height, new \ImagickPixel('#E11D48'));
        $img->setImageFormat('jpeg');
        $img->setImageProperty('exif:Make', 'LeakyPhone');
        $img->setImageProperty('exif:GPSLatitude', '48/1, 51/1, 24/1');
        $img->writeImage($path);
        $img->clear();

        return $path;
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
