<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Poi\VisibilityInputDto;
use App\Dto\Social\ReviewInputDto;
use App\Entity\ActivityEvent;
use App\Entity\Reaction;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\PoiRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReviewRepository;
use App\Security\Voter\ResourceOwnerVoter;
use App\Service\ActivityFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * REST API controller for creating, moderating, and toggling visibility of POI reviews.
 */
final class ReviewController extends AbstractController
{
    /**
     * Adds a new review to a POI (multiple reviews per user are allowed for repeat visits).
     *
     * @param string                 $id          UUID string of the target POI
     * @param ReviewInputDto         $dto         Validated rating and optional body/isPublic
     * @param User                   $user        Authenticated author
     * @param PoiRepository          $pois        POI repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/pois/{id}/reviews', name: 'api_pois_add_review', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(
        string $id,
        #[MapRequestPayload] ReviewInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $review = new Review($poi, $user, $dto->rating, $dto->body, $dto->isPublic);
        $em->persist($review);
        $feedService->recordEvent(
            $user,
            ActivityEvent::EVENT_REVIEW_ADDED,
            $poi,
            ActivityEvent::TARGET_REVIEW,
            $review->getId(),
            $review->isPublic(),
        );
        $em->flush();

        return $this->json($review, Response::HTTP_CREATED, context: ['groups' => ['review:read']]);
    }

    /**
     * Toggles the public visibility flag of an existing review (author or Admin only).
     *
     * @param string                 $id          UUID string of the target review
     * @param VisibilityInputDto     $dto         Validated visibility payload
     * @param ReviewRepository       $reviews     Review repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/reviews/{id}/visibility', name: 'api_reviews_update_visibility', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateVisibility(
        string $id,
        #[MapRequestPayload] VisibilityInputDto $dto,
        ReviewRepository $reviews,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $review = $reviews->find(Uuid::fromString($id));
        if ($review === null) {
            return $this->json(['error' => 'Review not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $review);

        $em->wrapInTransaction(static function () use ($review, $dto, $feedService, $em): void {
            $review->setIsPublic($dto->isPublic);
            $em->flush();

            $feedService->syncVisibility(ActivityEvent::TARGET_REVIEW, $review->getId(), $dto->isPublic);
        });

        return $this->json($review, context: ['groups' => ['review:read']]);
    }

    /**
     * Deletes a review (author or Admin only).
     *
     * Attached photos have their review_id set to NULL via ON DELETE SET NULL so they remain in the POI gallery.
     *
     * @param string                 $id          UUID string of the target review
     * @param ReviewRepository       $reviews     Review repository
     * @param ReactionRepository     $reactions   Reaction repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/reviews/{id}', name: 'api_reviews_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        ReviewRepository $reviews,
        ReactionRepository $reactions,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $review = $reviews->find(Uuid::fromString($id));
        if ($review === null) {
            return $this->json(['error' => 'Review not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $review);

        $reviewId = $review->getId();
        $reactionIds = $reactions->findIdsByTarget(Reaction::TARGET_REVIEW, $reviewId);

        $em->wrapInTransaction(static function () use ($em, $review, $reviewId, $reactionIds, $feedService): void {
            $em->remove($review);
            $em->flush();

            $feedService->deleteForTarget(ActivityEvent::TARGET_REVIEW, $reviewId);
            $feedService->deleteForTargets(ActivityEvent::TARGET_REACTION, $reactionIds);
        });

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
