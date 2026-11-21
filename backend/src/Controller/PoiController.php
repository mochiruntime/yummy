<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Poi\PoiCreateInputDto;
use App\Dto\Poi\PoiFilterQueryDto;
use App\Dto\Poi\PoiUpdateInputDto;
use App\Dto\Poi\VisibilityInputDto;
use App\Entity\ActivityEvent;
use App\Entity\Poi;
use App\Entity\User;
use App\Repository\PoiListRepository;
use App\Repository\PoiRepository;
use App\Repository\PoiRevisionRepository;
use App\Security\Voter\ResourceOwnerVoter;
use App\Service\ActivityFeedService;
use App\Service\PoiDetailAssembler;
use App\Service\PoiVersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * REST API controller for Points of Interest (POIs).
 *
 * Responsibilities:
 * - Querying POIs with spatial bounding-box viewports, list filters, and guest visibility constraints.
 * - Provisioning new POIs with automatic initial revision logging.
 * - Handling optimistic concurrency conflicts (HTTP 409) during concurrent edits.
 * - Managing granular privacy flags, authorization checks, and forward-reverting historical revisions.
 */
final class PoiController extends AbstractController
{
    /**
     * Queries POIs filtered by map viewport bounding box, category lists, and user authentication state.
     *
     * @param PoiRepository      $pois  POI repository for executing spatial queries
     * @param PoiFilterQueryDto  $query Validated bbox and listIds query parameters
     * @param User|null          $user  Current authenticated user, or null if guest
     */
    #[Route('/api/pois', name: 'api_pois_list', methods: ['GET'])]
    public function index(
        PoiRepository $pois,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] PoiFilterQueryDto $query = new PoiFilterQueryDto(),
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        if ($query->mine && $user === null) {
            return $this->json([]);
        }

        // Guests only see public POIs; authenticated members see public POIs and instance POIs
        $results = $pois->findByFilters(
            $query->toBbox(),
            $query->toListIds(),
            $user === null,
            $query->mine ? $user : null,
            $query->getTrimmedQuery(),
            $query->limit,
            $query->offset,
        );

        return $this->json($results, context: ['groups' => ['poi:summary']]);
    }

    /**
     * Creates a new POI and records its initial revision snapshot.
     *
     * @param PoiCreateInputDto      $dto        Validated request payload
     * @param User                   $user       Authenticated user creating the POI
     * @param PoiListRepository      $lists      Repository for linking existing lists
     * @param PoiVersioningService   $versioning Service for capturing the initial revision
     * @param EntityManagerInterface $em         Doctrine entity manager
     */
    #[Route('/api/pois', name: 'api_pois_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(
        #[MapRequestPayload] PoiCreateInputDto $dto,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        PoiVersioningService $versioning,
        PoiDetailAssembler $detailAssembler,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = new Poi(
            $user,
            $dto->name,
            $dto->latitude,
            $dto->longitude,
            $dto->description,
            $dto->address,
            $dto->isPublic,
        );

        // Attach existing lists if supplied (UUID format is already validated by PoiCreateInputDto)
        foreach ($dto->listIds as $listId) {
            $list = $lists->find(Uuid::fromString($listId));
            if ($list !== null) {
                $poi->addList($list);
            }
        }

        $em->persist($poi);
        $em->flush();

        // Capture initial revision 1 for audit and future revert history
        $versioning->recordRevision($poi, $user, [
            'initial' => true,
            'snapshot' => $versioning->buildSnapshot($poi),
        ]);
        $feedService->recordEvent(
            $user,
            ActivityEvent::EVENT_POI_CREATED,
            $poi,
            ActivityEvent::TARGET_POI,
            $poi->getId(),
            $poi->isPublic(),
        );
        $em->flush();

        return $this->json($detailAssembler->assemble($poi, $user), Response::HTTP_CREATED);
    }

    /**
     * Retrieves full POI details, enforcing public-only visibility for unauthenticated guests.
     *
     * @param string             $id              UUID string of the target POI
     * @param PoiRepository      $pois            POI repository
     * @param PoiDetailAssembler $detailAssembler Social detail assembler
     * @param User|null          $user            Authenticated user, or null if guest
     */
    #[Route('/api/pois/{id}', name: 'api_pois_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(
        string $id,
        PoiRepository $pois,
        PoiDetailAssembler $detailAssembler,
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Guests can only see public POIs. Return 404 to avoid leaking the existence of private POIs.
        if ($user === null && !$poi->isPublic()) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($detailAssembler->assemble($poi, $user));
    }

    /**
     * Updates editable POI attributes with optimistic locking and revision recording.
     *
     * @param string                 $id         UUID string of the target POI
     * @param PoiUpdateInputDto      $dto        Validated update payload with client's known version
     * @param User                   $user       Authenticated editor
     * @param PoiRepository          $pois       POI repository
     * @param PoiVersioningService   $versioning Revision audit service
     * @param EntityManagerInterface $em         Doctrine entity manager
     */
    #[Route('/api/pois/{id}', name: 'api_pois_update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(
        string $id,
        #[MapRequestPayload] PoiUpdateInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        PoiVersioningService $versioning,
        PoiDetailAssembler $detailAssembler,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Optimistic concurrency check: if the client's known version does not match current version, reject
        if ($dto->version !== $poi->getVersion()) {
            return $this->json(
                ['error' => 'Conflict: POI has been modified by another edit. Reload to see latest version.'],
                Response::HTTP_CONFLICT,
            );
        }

        /** @var array<string, mixed> $diff */
        $diff = [];
        if ($dto->name !== null && $dto->name !== $poi->getName()) {
            $diff['name'] = ['from' => $poi->getName(), 'to' => $dto->name];
            $poi->setName($dto->name);
        }

        if ($dto->description !== null && $dto->description !== $poi->getDescription()) {
            $diff['description'] = ['from' => $poi->getDescription(), 'to' => $dto->description];
            $poi->setDescription($dto->description);
        }

        if ($dto->address !== null && $dto->address !== $poi->getAddress()) {
            $diff['address'] = ['from' => $poi->getAddress(), 'to' => $dto->address];
            $poi->setAddress($dto->address);
        }

        if ($dto->latitude !== null && $dto->longitude !== null) {
            $diff['coordinates'] = [
                'from' => ['lat' => $poi->getLatitude(), 'lng' => $poi->getLongitude()],
                'to' => ['lat' => $dto->latitude, 'lng' => $dto->longitude],
            ];
            $poi->setCoordinates($dto->latitude, $dto->longitude);
        }

        // Flush POI changes; catch concurrent modifications handled by Doctrine #[ORM\Version]
        try {
            $em->flush();
        } catch (\Doctrine\ORM\OptimisticLockException) {
            return $this->json(
                ['error' => 'Conflict: POI has been modified by another edit. Reload to see latest version.'],
                Response::HTTP_CONFLICT,
            );
        }

        // Record the revision diff and updated state snapshot
        $versioning->recordRevision($poi, $user, [
            'diff' => $diff,
            'snapshot' => $versioning->buildSnapshot($poi),
        ]);

        if ($diff !== []) {
            $feedService->recordEvent(
                $user,
                ActivityEvent::EVENT_POI_EDITED,
                $poi,
                ActivityEvent::TARGET_POI,
                $poi->getId(),
                $poi->isPublic(),
                ['changedFields' => array_keys($diff)],
            );
        }

        $em->flush();

        return $this->json($detailAssembler->assemble($poi, $user));
    }

    /**
     * Toggles the POI's public visibility flag (creator or Admin only).
     *
     * @param string                 $id          UUID string of the target POI
     * @param VisibilityInputDto     $dto         Validated boolean payload
     * @param PoiRepository          $pois        POI repository
     * @param ActivityFeedService    $feedService Activity feed service
     * @param EntityManagerInterface $em          Doctrine entity manager
     */
    #[Route('/api/pois/{id}/visibility', name: 'api_pois_update_visibility', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateVisibility(
        string $id,
        #[MapRequestPayload] VisibilityInputDto $dto,
        PoiRepository $pois,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can alter visibility
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $poi);

        $em->wrapInTransaction(static function () use ($poi, $dto, $feedService, $em): void {
            $poi->setIsPublic($dto->isPublic);
            $em->flush();

            $feedService->syncVisibility(ActivityEvent::TARGET_POI, $poi->getId(), $dto->isPublic);
        });

        return $this->json($poi, context: ['groups' => ['poi:summary']]);
    }

    /**
     * Deletes a POI from the system (creator or Admin only).
     *
     * @param string                 $id   UUID string of the target POI
     * @param PoiRepository          $pois POI repository
     * @param EntityManagerInterface $em   Doctrine entity manager
     */
    #[Route('/api/pois/{id}', name: 'api_pois_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can delete a POI
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $poi);

        $em->remove($poi);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Retrieves the chronological revision history of a POI.
     *
     * @param string                $id        UUID string of the target POI
     * @param PoiRepository         $pois      POI repository
     * @param PoiRevisionRepository $revisions Revision repository
     */
    #[Route('/api/pois/{id}/revisions', name: 'api_pois_revisions', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function listRevisions(
        string $id,
        PoiRepository $pois,
        PoiRevisionRepository $revisions,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $history = $revisions->findByPoiOrderedByRevisionDesc($poi);

        return $this->json($history, context: ['groups' => ['poi:revision']]);
    }

    /**
     * Reverts a POI to a prior revision (creator or Admin only).
     *
     * @param string               $id             UUID string of the target POI
     * @param int                  $revisionNumber Target revision number to restore from
     * @param User                 $user           Authenticated caller
     * @param PoiRepository        $pois           POI repository
     * @param PoiVersioningService $versioning     Revision audit service
     */
    #[Route('/api/pois/{id}/revisions/{revisionNumber}/revert', name: 'api_pois_revert_revision', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function revertRevision(
        string $id,
        int $revisionNumber,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        PoiVersioningService $versioning,
        PoiDetailAssembler $detailAssembler,
        ActivityFeedService $feedService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can trigger a revert
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $poi);

        // Forward revert: restore attributes and record a new revision N+1
        $reverted = $versioning->revertToRevision($poi, $revisionNumber, $user);
        if ($reverted === null) {
            return $this->json(['error' => 'Target revision not found or invalid.'], Response::HTTP_NOT_FOUND);
        }

        $feedService->recordEvent(
            $user,
            ActivityEvent::EVENT_POI_EDITED,
            $poi,
            ActivityEvent::TARGET_POI,
            $poi->getId(),
            $poi->isPublic(),
            ['revertedToRevision' => $revisionNumber],
        );
        $em->flush();

        return $this->json($detailAssembler->assemble($poi, $user));
    }
}
