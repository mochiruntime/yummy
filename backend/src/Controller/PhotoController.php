<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Poi\VisibilityInputDto;
use App\Dto\Social\PhotoUploadInputDto;
use App\Entity\ActivityEvent;
use App\Entity\Photo;
use App\Entity\Reaction;
use App\Entity\Review;
use App\Entity\ReviewItem;
use App\Entity\User;
use App\Repository\PhotoRepository;
use App\Repository\PoiRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReviewItemRepository;
use App\Repository\ReviewRepository;
use App\Security\Voter\ResourceOwnerVoter;
use App\Service\ActivityFeedService;
use App\Service\ImageProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * REST API controller for EXIF-stripped photo uploads, visibility toggling, deletion, and secure streaming.
 */
final class PhotoController extends AbstractController
{
    /**
     * Uploads a photo to a POI (and optionally links it to a review), stripping all EXIF/GPS metadata.
     *
     * @param string                   $id             UUID string of the target POI
     * @param User                     $user           Authenticated uploader
     * @param PoiRepository            $pois           POI repository
     * @param ReviewRepository         $reviews        Review repository
     * @param ImageProcessingService   $imageProcessor EXIF-stripping and thumbnailing service
     * @param ActivityFeedService      $feedService    Activity feed service
     * @param EntityManagerInterface   $em             Doctrine entity manager
     * @param PhotoUploadInputDto|null $dto            Validated multipart metadata (caption, reviewId, reviewItemId, isPublic)
     * @param UploadedFile|null        $file           Uploaded image file
     */
    #[Route('/api/pois/{id}/photos', name: 'api_pois_upload_photo', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function upload(
        string $id,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        ReviewRepository $reviews,
        ReviewItemRepository $reviewItems,
        ImageProcessingService $imageProcessor,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
        #[MapRequestPayload] ?PhotoUploadInputDto $dto = null,
        #[MapUploadedFile] ?UploadedFile $file = null,
    ): JsonResponse {
        $dto ??= new PhotoUploadInputDto();
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($file === null) {
            return $this->json(['error' => 'Missing "file" upload in multipart request.'], Response::HTTP_BAD_REQUEST);
        }

        // Validate optional reviewId belongs to this same POI and is owned by the uploader
        $review = null;
        if ($dto->reviewId !== null && $dto->reviewId !== '') {
            $review = $reviews->find(Uuid::fromString($dto->reviewId));
            if (!$review instanceof Review || $review->getPoi()->getId()->toRfc4122() !== $poi->getId()->toRfc4122()) {
                return $this->json(['error' => 'Target review not found on this POI.'], Response::HTTP_NOT_FOUND);
            }

            $this->denyAccessUnlessGranted(ResourceOwnerVoter::OWN, $review);
        }

        // Validate optional reviewItemId belongs to the target POI and (if provided) target review
        $reviewItem = null;
        if ($dto->reviewItemId !== null && $dto->reviewItemId !== '') {
            $reviewItem = $reviewItems->find(Uuid::fromString($dto->reviewItemId));
            if (!$reviewItem instanceof ReviewItem || $reviewItem->getReview()->getPoi()->getId()->toRfc4122() !== $poi->getId()->toRfc4122()) {
                return $this->json(['error' => 'Target review item not found on this POI.'], Response::HTTP_NOT_FOUND);
            }

            if ($review !== null && $reviewItem->getReview()->getId()->toRfc4122() !== $review->getId()->toRfc4122()) {
                return $this->json(['error' => 'Target review item does not belong to the specified review.'], Response::HTTP_BAD_REQUEST);
            }

            if ($review === null) {
                $review = $reviewItem->getReview();
                $this->denyAccessUnlessGranted(ResourceOwnerVoter::OWN, $review);
            }
        }

        $caption = trim((string) $dto->caption);

        try {
            $processed = $imageProcessor->processAndStore($file);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $photo = new Photo(
            $poi,
            $user,
            $processed['storageKey'],
            $processed['mimeType'],
            $processed['width'],
            $processed['height'],
            $review,
            $caption !== '' ? $caption : null,
            $dto->isPublic,
            $reviewItem,
        );

        $em->persist($photo);
        $feedService->recordEvent(
            $user,
            ActivityEvent::EVENT_PHOTO_ADDED,
            $poi,
            ActivityEvent::TARGET_PHOTO,
            $photo->getId(),
            $photo->isPublic(),
            $review !== null ? ['reviewId' => $review->getId()->toRfc4122()] : [],
        );
        $em->flush();

        return $this->json($photo, Response::HTTP_CREATED, context: ['groups' => ['photo:read']]);
    }

    /**
     * Toggles the public visibility flag on a single photo (uploader or Admin only).
     *
     * @param string                 $id          UUID string of the target photo
     * @param VisibilityInputDto     $dto         Validated visibility payload
     * @param PhotoRepository        $photos      Photo repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/photos/{id}/visibility', name: 'api_photos_update_visibility', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateVisibility(
        string $id,
        #[MapRequestPayload] VisibilityInputDto $dto,
        PhotoRepository $photos,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $photo = $photos->find(Uuid::fromString($id));
        if ($photo === null) {
            return $this->json(['error' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $photo);

        $em->wrapInTransaction(static function () use ($photo, $dto, $feedService, $em): void {
            $photo->setIsPublic($dto->isPublic);
            $em->flush();

            $feedService->syncVisibility(ActivityEvent::TARGET_PHOTO, $photo->getId(), $dto->isPublic);
        });

        return $this->json($photo, context: ['groups' => ['photo:read']]);
    }

    /**
     * Deletes a photo and removes its stored full/thumbnail files from disk (uploader or Admin only).
     *
     * @param string                 $id             UUID string of the target photo
     * @param PhotoRepository        $photos         Photo repository
     * @param ReactionRepository     $reactions      Reaction repository
     * @param ImageProcessingService $imageProcessor Service to clean up stored files
     * @param ActivityFeedService    $feedService    Activity feed service
     * @param EntityManagerInterface $em             Doctrine entity manager
     */
    #[Route('/api/photos/{id}', name: 'api_photos_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        PhotoRepository $photos,
        ReactionRepository $reactions,
        ImageProcessingService $imageProcessor,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $photo = $photos->find(Uuid::fromString($id));
        if ($photo === null) {
            return $this->json(['error' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $photo);

        $photoId = $photo->getId();
        $reactionIds = $reactions->findIdsByTarget(Reaction::TARGET_PHOTO, $photoId);

        $em->wrapInTransaction(static function () use ($em, $photo, $photoId, $reactionIds, $feedService): void {
            $em->remove($photo);
            $em->flush();

            $feedService->deleteForTarget(ActivityEvent::TARGET_PHOTO, $photoId);
            $feedService->deleteForTargets(ActivityEvent::TARGET_REACTION, $reactionIds);
        });

        $imageProcessor->deleteFiles($photo);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Streams EXIF-stripped photo bytes while strictly enforcing parent-child visibility.
     *
     * Guests receive HTTP 404 (enumeration prevention) unless:
     * - photo.isPublic === true
     * - AND photo.poi.isPublic === true
     * - AND (photo.review === null || photo.review.isPublic === true)
     *
     * @param string                 $id             UUID string of the target photo
     * @param PhotoRepository        $photos         Photo repository
     * @param ImageProcessingService $imageProcessor Service resolving variant file paths
     * @param string                 $size           Requested image variant ("thumb" or "full")
     * @param User|null              $user           Current authenticated user, or null for guests
     */
    #[Route('/api/photos/{id}/file', name: 'api_photos_get_file', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function streamFile(
        string $id,
        PhotoRepository $photos,
        ImageProcessingService $imageProcessor,
        #[MapQueryParameter] string $size = 'full',
        #[CurrentUser] ?User $user = null,
    ): Response {
        $photo = $photos->find(Uuid::fromString($id));
        if ($photo === null) {
            return $this->json(['error' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        // Enforce strict parent-child visibility invariant for unauthenticated guests (return 404 to prevent enumeration)
        if ($user === null) {
            $review = $photo->getReview();
            $isVisibleToGuest = $photo->isPublic()
                && $photo->getPoi()->isPublic()
                && ($review === null || $review->isPublic());

            if (!$isVisibleToGuest) {
                return $this->json(['error' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
            }
        }

        if ($size !== 'thumb' && $size !== 'full') {
            return $this->json(['error' => 'Invalid size parameter. Expected "thumb" or "full".'], Response::HTTP_BAD_REQUEST);
        }

        $path = $imageProcessor->resolveFilePath($photo, $size);
        if (!is_file($path)) {
            return $this->json(['error' => 'Photo file not found on disk.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $photo->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', $user === null ? 'public, max-age=86400' : 'private, max-age=86400');

        return $response;
    }
}
