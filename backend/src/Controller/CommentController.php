<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Poi\VisibilityInputDto;
use App\Dto\Social\CommentInputDto;
use App\Entity\ActivityEvent;
use App\Entity\Comment;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PoiRepository;
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
 * REST API controller for posting, moderating, and toggling visibility of POI comments.
 */
final class CommentController extends AbstractController
{
    /**
     * Posts a discussion comment on a POI (defaults to isPublic = false).
     *
     * @param string                 $id          UUID string of the target POI
     * @param CommentInputDto        $dto         Validated comment body and optional isPublic flag
     * @param User                   $user        Authenticated author
     * @param PoiRepository          $pois        POI repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/pois/{id}/comments', name: 'api_pois_add_comment', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(
        string $id,
        #[MapRequestPayload] CommentInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $comment = new Comment($poi, $user, $dto->body, $dto->isPublic);
        $em->persist($comment);
        $feedService->recordEvent(
            $user,
            ActivityEvent::EVENT_COMMENT_ADDED,
            $poi,
            ActivityEvent::TARGET_COMMENT,
            $comment->getId(),
            $comment->isPublic(),
        );
        $em->flush();

        return $this->json($comment, Response::HTTP_CREATED, context: ['groups' => ['comment:read']]);
    }

    /**
     * Toggles the public visibility flag of an existing comment (author or Admin only).
     *
     * @param string                 $id          UUID string of the target comment
     * @param VisibilityInputDto     $dto         Validated visibility payload
     * @param CommentRepository      $comments    Comment repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/comments/{id}/visibility', name: 'api_comments_update_visibility', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateVisibility(
        string $id,
        #[MapRequestPayload] VisibilityInputDto $dto,
        CommentRepository $comments,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $comment = $comments->find(Uuid::fromString($id));
        if ($comment === null) {
            return $this->json(['error' => 'Comment not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $comment);

        $em->wrapInTransaction(static function () use ($comment, $dto, $feedService, $em): void {
            $comment->setIsPublic($dto->isPublic);
            $em->flush();

            $feedService->syncVisibility(ActivityEvent::TARGET_COMMENT, $comment->getId(), $dto->isPublic);
        });

        return $this->json($comment, context: ['groups' => ['comment:read']]);
    }

    /**
     * Deletes a comment (author or Admin only).
     *
     * @param string                 $id          UUID string of the target comment
     * @param CommentRepository      $comments    Comment repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/comments/{id}', name: 'api_comments_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        CommentRepository $comments,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $comment = $comments->find(Uuid::fromString($id));
        if ($comment === null) {
            return $this->json(['error' => 'Comment not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $comment);

        $commentId = $comment->getId();
        $em->wrapInTransaction(static function () use ($em, $comment, $commentId, $feedService): void {
            $em->remove($comment);
            $em->flush();

            $feedService->deleteForTarget(ActivityEvent::TARGET_COMMENT, $commentId);
        });

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
