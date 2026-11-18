<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Social\ReactionInputDto;
use App\Entity\Photo;
use App\Entity\Poi;
use App\Entity\Reaction;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\PhotoRepository;
use App\Repository\PoiRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReviewRepository;
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
 * REST API controller for toggling emoji reactions on POIs, Reviews, and Photos.
 */
final class ReactionController extends AbstractController
{
    /**
     * Toggles an emoji reaction on a POI for the current authenticated user.
     *
     * @param string                 $id        UUID string of the target POI
     * @param ReactionInputDto       $dto       Validated emoji and optional isPublic flag
     * @param User                   $user      Authenticated caller
     * @param PoiRepository          $pois      POI repository
     * @param ReactionRepository     $reactions Reaction repository
     * @param EntityManagerInterface $em        Doctrine entity manager
     */
    #[Route('/api/pois/{id}/reactions', name: 'api_pois_toggle_reaction', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function togglePoi(
        string $id,
        #[MapRequestPayload] ReactionInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        ReactionRepository $reactions,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->toggleForTarget(
            Reaction::TARGET_POI,
            $poi->getId(),
            $poi,
            null,
            null,
            $dto,
            $user,
            $reactions,
            $em,
        );
    }

    /**
     * Toggles an emoji reaction on a Review for the current authenticated user.
     *
     * @param string                 $id        UUID string of the target Review
     * @param ReactionInputDto       $dto       Validated emoji and optional isPublic flag
     * @param User                   $user      Authenticated caller
     * @param ReviewRepository       $reviews   Review repository
     * @param ReactionRepository     $reactions Reaction repository
     * @param EntityManagerInterface $em        Doctrine entity manager
     */
    #[Route('/api/reviews/{id}/reactions', name: 'api_reviews_toggle_reaction', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function toggleReview(
        string $id,
        #[MapRequestPayload] ReactionInputDto $dto,
        #[CurrentUser] User $user,
        ReviewRepository $reviews,
        ReactionRepository $reactions,
        EntityManagerInterface $em,
    ): JsonResponse {
        $review = $reviews->find(Uuid::fromString($id));
        if ($review === null) {
            return $this->json(['error' => 'Review not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->toggleForTarget(
            Reaction::TARGET_REVIEW,
            $review->getId(),
            $review->getPoi(),
            $review,
            null,
            $dto,
            $user,
            $reactions,
            $em,
        );
    }

    /**
     * Toggles an emoji reaction on a Photo for the current authenticated user.
     *
     * @param string                 $id        UUID string of the target Photo
     * @param ReactionInputDto       $dto       Validated emoji and optional isPublic flag
     * @param User                   $user      Authenticated caller
     * @param PhotoRepository        $photos    Photo repository
     * @param ReactionRepository     $reactions Reaction repository
     * @param EntityManagerInterface $em        Doctrine entity manager
     */
    #[Route('/api/photos/{id}/reactions', name: 'api_photos_toggle_reaction', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function togglePhoto(
        string $id,
        #[MapRequestPayload] ReactionInputDto $dto,
        #[CurrentUser] User $user,
        PhotoRepository $photos,
        ReactionRepository $reactions,
        EntityManagerInterface $em,
    ): JsonResponse {
        $photo = $photos->find(Uuid::fromString($id));
        if ($photo === null) {
            return $this->json(['error' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->toggleForTarget(
            Reaction::TARGET_PHOTO,
            $photo->getId(),
            $photo->getPoi(),
            null,
            $photo,
            $dto,
            $user,
            $reactions,
            $em,
        );
    }

    /**
     * Shared toggle handler for POI, Review, and Photo reaction endpoints.
     */
    private function toggleForTarget(
        string $targetType,
        Uuid $targetId,
        Poi $poi,
        ?Review $review,
        ?Photo $photo,
        ReactionInputDto $dto,
        User $user,
        ReactionRepository $reactions,
        EntityManagerInterface $em,
    ): JsonResponse {
        $existing = $reactions->findOneByTargetUserAndEmoji($targetType, $targetId, $user, $dto->emoji);
        if ($existing !== null) {
            $em->remove($existing);
            $reacted = false;
        } else {
            $reaction = new Reaction($poi, $user, $dto->emoji, $dto->isPublic, $review, $photo);
            $em->persist($reaction);
            $reacted = true;
        }

        $em->flush();

        return $this->json([
            'reacted' => $reacted,
            'reactions' => $reactions->getAggregatedForTarget($targetType, $targetId, $user),
        ]);
    }
}
