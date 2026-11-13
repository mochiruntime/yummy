<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Poi\PoiCreateInputDto;
use App\Dto\Poi\PoiUpdateInputDto;
use App\Dto\Poi\VisibilityInputDto;
use App\Entity\Poi;
use App\Entity\User;
use App\Repository\PoiListRepository;
use App\Repository\PoiRepository;
use App\Repository\PoiRevisionRepository;
use App\Service\PoiVersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
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
     * @param Request            $request Current HTTP request containing query params ('bbox', 'listIds')
     * @param PoiRepository      $pois    POI repository for executing spatial queries
     * @param User|null          $user    Current authenticated user, or null if guest
     */
    #[Route('/api/pois', name: 'api_pois_list', methods: ['GET'])]
    public function index(
        Request $request,
        PoiRepository $pois,
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        $bbox = null;
        $rawBbox = $request->query->getString('bbox', '');

        // Parse and validate bounding-box query parameter: minLng,minLat,maxLng,maxLat
        if ($rawBbox !== '') {
            $parts = explode(',', $rawBbox);
            if (count($parts) === 4 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2]) && is_numeric($parts[3])) {
                $minLng = (float) $parts[0];
                $minLat = (float) $parts[1];
                $maxLng = (float) $parts[2];
                $maxLat = (float) $parts[3];

                // Validate coordinate ranges to prevent malformed or inverted geometry queries
                if ($minLat < -90.0 || $maxLat > 90.0 || $minLng < -180.0 || $maxLng > 180.0 || $minLat > $maxLat || $minLng > $maxLng) {
                    return $this->json(['error' => 'Invalid bbox bounds.'], Response::HTTP_BAD_REQUEST);
                }

                $bbox = [
                    'minLng' => $minLng,
                    'minLat' => $minLat,
                    'maxLng' => $maxLng,
                    'maxLat' => $maxLat,
                ];
            } else {
                return $this->json(['error' => 'Invalid bbox format. Expected minLng,minLat,maxLng,maxLat.'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Parse optional comma-separated list of category UUID filters
        $listIds = [];
        $rawLists = $request->query->getString('listIds', '');
        if ($rawLists !== '') {
            $listIds = array_values(array_filter(array_map('trim', explode(',', $rawLists))));
        }

        // Guests only see public POIs; authenticated members see public POIs and instance POIs
        $isGuest = ($user === null);
        $results = $pois->findByFilters($bbox, $listIds, $isGuest);

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

        // Attach existing valid lists if supplied
        foreach ($dto->listIds as $listId) {
            if (Uuid::isValid($listId)) {
                $list = $lists->find(Uuid::fromString($listId));
                if ($list !== null) {
                    $poi->addList($list);
                }
            }
        }

        $em->persist($poi);
        $em->flush();

        // Capture initial revision 1 for audit and future revert history
        $versioning->recordRevision($poi, $user, [
            'initial' => true,
            'snapshot' => $versioning->buildSnapshot($poi),
        ]);
        $em->flush();

        return $this->json($poi, Response::HTTP_CREATED, context: ['groups' => ['poi:read']]);
    }

    /**
     * Retrieves full POI details, enforcing public-only visibility for unauthenticated guests.
     *
     * @param string        $id   UUID string of the target POI
     * @param PoiRepository $pois POI repository
     * @param User|null     $user Authenticated user, or null if guest
     */
    #[Route('/api/pois/{id}', name: 'api_pois_get', methods: ['GET'])]
    public function show(
        string $id,
        PoiRepository $pois,
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Guests can only see public POIs. Return 404 to avoid leaking the existence of private POIs.
        if ($user === null && !$poi->isPublic()) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($poi, context: ['groups' => ['poi:read']]);
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
    #[Route('/api/pois/{id}', name: 'api_pois_update', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(
        string $id,
        #[MapRequestPayload] PoiUpdateInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        PoiVersioningService $versioning,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

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

        $em->flush();

        return $this->json($poi, context: ['groups' => ['poi:read']]);
    }

    /**
     * Toggles the POI's public visibility flag (creator or Admin only).
     *
     * @param string                 $id   UUID string of the target POI
     * @param VisibilityInputDto     $dto  Validated boolean payload
     * @param User                   $user Authenticated caller
     * @param PoiRepository          $pois POI repository
     * @param EntityManagerInterface $em   Doctrine entity manager
     */
    #[Route('/api/pois/{id}/visibility', name: 'api_pois_update_visibility', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateVisibility(
        string $id,
        #[MapRequestPayload] VisibilityInputDto $dto,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can alter visibility
        if ($poi->getCreatedBy()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $poi->setIsPublic($dto->isPublic);
        $em->flush();

        return $this->json($poi, context: ['groups' => ['poi:summary']]);
    }

    /**
     * Deletes a POI from the system (creator or Admin only).
     *
     * @param string                 $id   UUID string of the target POI
     * @param User                   $user Authenticated caller
     * @param PoiRepository          $pois POI repository
     * @param EntityManagerInterface $em   Doctrine entity manager
     */
    #[Route('/api/pois/{id}', name: 'api_pois_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can delete a POI
        if ($poi->getCreatedBy()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

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
    #[Route('/api/pois/{id}/revisions', name: 'api_pois_revisions', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function listRevisions(
        string $id,
        PoiRepository $pois,
        PoiRevisionRepository $revisions,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

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
    #[Route('/api/pois/{id}/revisions/{revisionNumber}/revert', name: 'api_pois_revert_revision', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function revertRevision(
        string $id,
        int $revisionNumber,
        #[CurrentUser] User $user,
        PoiRepository $pois,
        PoiVersioningService $versioning,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        $poi = $pois->find(Uuid::fromString($id));
        if ($poi === null) {
            return $this->json(['error' => 'POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the creator or an instance Admin can trigger a revert
        if ($poi->getCreatedBy()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        // Forward revert: restore attributes and record a new revision N+1
        $reverted = $versioning->revertToRevision($poi, $revisionNumber, $user);
        if ($reverted === null) {
            return $this->json(['error' => 'Target revision not found or invalid.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($poi, context: ['groups' => ['poi:read']]);
    }
}
