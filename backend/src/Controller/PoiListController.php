<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\List\PoiListInputDto;
use App\Entity\Poi;
use App\Entity\PoiList;
use App\Entity\User;
use App\Repository\PoiListRepository;
use App\Repository\PoiRepository;
use App\Security\Voter\ResourceOwnerVoter;
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
 * REST API controller for managing curated POI lists / categories.
 *
 * Responsibilities:
 * - Querying all available lists ordered alphabetically.
 * - Creating lists with owner-scoped unique slugs (e.g., "best-ramen", "best-ramen-2").
 * - Updating list presentation (name, slug, color accent, icon).
 * - Adding and removing POIs from a list with strict ownership/admin enforcement.
 * - Deleting lists while safely preserving attached POIs.
 */
final class PoiListController extends AbstractController
{
    /**
     * Lists all curated POI lists/categories in alphabetical order.
     *
     * @param PoiListRepository $lists List repository
     */
    #[Route('/api/lists', name: 'api_lists_list', methods: ['GET'])]
    public function index(PoiListRepository $lists): JsonResponse
    {
        $all = $lists->findAllOrderedByName();

        return $this->json($all, context: ['groups' => ['list:read']]);
    }

    /**
     * Creates a new curated list owned by the authenticated caller.
     *
     * @param PoiListInputDto        $dto   Validated payload (name, color, icon)
     * @param User                   $user  Authenticated owner
     * @param PoiListRepository      $lists List repository for slug generation
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists', name: 'api_lists_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(
        #[MapRequestPayload] PoiListInputDto $dto,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        EntityManagerInterface $em,
    ): JsonResponse {
        // Generate an owner-scoped unique slug (e.g., "tokyo-2026", "tokyo-2026-2")
        $slug = $lists->generateUniqueSlug($user, $dto->name);
        $list = new PoiList($user, $dto->name, $slug, $dto->color, $dto->icon);

        $em->persist($list);
        $em->flush();

        return $this->json($list, Response::HTTP_CREATED, context: ['groups' => ['list:read']]);
    }

    /**
     * Renames or updates visual styling (color, icon) of an existing list.
     *
     * @param string                 $id    UUID string of the target list
     * @param PoiListInputDto        $dto   Validated update payload
     * @param PoiListRepository      $lists List repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}', name: 'api_lists_update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(
        string $id,
        #[MapRequestPayload] PoiListInputDto $dto,
        PoiListRepository $lists,
        EntityManagerInterface $em,
    ): JsonResponse {
        $list = $lists->find(Uuid::fromString($id));
        if ($list === null) {
            return $this->json(['error' => 'List not found.'], Response::HTTP_NOT_FOUND);
        }

        // Authorization check: Only the owner or an instance Admin may modify list metadata
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $list);

        // Regenerate unique slug if the list name changed
        if ($dto->name !== '' && $dto->name !== $list->getName()) {
            $list->setName($dto->name);
            $list->setSlug($lists->generateUniqueSlug($list->getOwner(), $dto->name));
        }

        $list->setColor($dto->color);
        $list->setIcon($dto->icon);

        $em->flush();

        return $this->json($list, context: ['groups' => ['list:read']]);
    }

    /**
     * Deletes a curated list. Attached POIs are preserved (only join table rows are removed).
     *
     * @param string                 $id    UUID string of the target list
     * @param PoiListRepository      $lists List repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}', name: 'api_lists_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        PoiListRepository $lists,
        EntityManagerInterface $em,
    ): JsonResponse {
        $list = $lists->find(Uuid::fromString($id));
        if ($list === null) {
            return $this->json(['error' => 'List not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the owner or an instance Admin may delete the list
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $list);

        $em->remove($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Adds an existing POI to a curated list.
     *
     * @param string                 $id    UUID string of the target list
     * @param string                 $poiId UUID string of the target POI
     * @param PoiListRepository      $lists List repository
     * @param PoiRepository          $pois  POI repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}/pois/{poiId}', name: 'api_lists_add_poi', requirements: ['id' => Requirement::UUID, 'poiId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addPoi(
        string $id,
        string $poiId,
        PoiListRepository $lists,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        $list = $lists->find(Uuid::fromString($id));
        $poi = $pois->find(Uuid::fromString($poiId));

        if ($list === null || $poi === null) {
            return $this->json(['error' => 'List or POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the list owner or an instance Admin may curate list members
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $list);

        $poi->addList($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Removes a POI from a curated list.
     *
     * @param string                 $id    UUID string of the target list
     * @param string                 $poiId UUID string of the target POI
     * @param PoiListRepository      $lists List repository
     * @param PoiRepository          $pois  POI repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}/pois/{poiId}', name: 'api_lists_remove_poi', requirements: ['id' => Requirement::UUID, 'poiId' => Requirement::UUID], methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removePoi(
        string $id,
        string $poiId,
        PoiListRepository $lists,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        $list = $lists->find(Uuid::fromString($id));
        $poi = $pois->find(Uuid::fromString($poiId));

        if ($list === null || $poi === null) {
            return $this->json(['error' => 'List or POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the list owner or an instance Admin may curate list members
        $this->denyAccessUnlessGranted(ResourceOwnerVoter::MANAGE, $list);

        $poi->removeList($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
