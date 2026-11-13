<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\List\PoiListInputDto;
use App\Entity\Poi;
use App\Entity\PoiList;
use App\Entity\User;
use App\Repository\PoiListRepository;
use App\Repository\PoiRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
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
     * @param User                   $user  Authenticated caller (must be list owner or Admin)
     * @param PoiListRepository      $lists List repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}', name: 'api_lists_update', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(
        string $id,
        #[MapRequestPayload] PoiListInputDto $dto,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid ID.'], Response::HTTP_NOT_FOUND);
        }

        $list = $lists->find(Uuid::fromString($id));
        if ($list === null) {
            return $this->json(['error' => 'List not found.'], Response::HTTP_NOT_FOUND);
        }

        // Authorization check: Only the owner or an instance Admin may modify list metadata
        if ($list->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

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
     * @param User                   $user  Authenticated caller (must be list owner or Admin)
     * @param PoiListRepository      $lists List repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}', name: 'api_lists_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(
        string $id,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid ID.'], Response::HTTP_NOT_FOUND);
        }

        $list = $lists->find(Uuid::fromString($id));
        if ($list === null) {
            return $this->json(['error' => 'List not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the owner or an instance Admin may delete the list
        if ($list->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $em->remove($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Adds an existing POI to a curated list.
     *
     * @param string                 $id    UUID string of the target list
     * @param string                 $poiId UUID string of the target POI
     * @param User                   $user  Authenticated caller (must be list owner or Admin)
     * @param PoiListRepository      $lists List repository
     * @param PoiRepository          $pois  POI repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}/pois/{poiId}', name: 'api_lists_add_poi', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addPoi(
        string $id,
        string $poiId,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id) || !Uuid::isValid($poiId)) {
            return $this->json(['error' => 'Invalid ID.'], Response::HTTP_NOT_FOUND);
        }

        $list = $lists->find(Uuid::fromString($id));
        $poi = $pois->find(Uuid::fromString($poiId));

        if ($list === null || $poi === null) {
            return $this->json(['error' => 'List or POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the list owner or an instance Admin may curate list members
        if ($list->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Only the list owner or an Admin can add POIs to this list.'], Response::HTTP_FORBIDDEN);
        }

        $poi->addList($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Removes a POI from a curated list.
     *
     * @param string                 $id    UUID string of the target list
     * @param string                 $poiId UUID string of the target POI
     * @param User                   $user  Authenticated caller (must be list owner or Admin)
     * @param PoiListRepository      $lists List repository
     * @param PoiRepository          $pois  POI repository
     * @param EntityManagerInterface $em    Doctrine entity manager
     */
    #[Route('/api/lists/{id}/pois/{poiId}', name: 'api_lists_remove_poi', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function removePoi(
        string $id,
        string $poiId,
        #[CurrentUser] User $user,
        PoiListRepository $lists,
        PoiRepository $pois,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id) || !Uuid::isValid($poiId)) {
            return $this->json(['error' => 'Invalid ID.'], Response::HTTP_NOT_FOUND);
        }

        $list = $lists->find(Uuid::fromString($id));
        $poi = $pois->find(Uuid::fromString($poiId));

        if ($list === null || $poi === null) {
            return $this->json(['error' => 'List or POI not found.'], Response::HTTP_NOT_FOUND);
        }

        // Only the list owner or an instance Admin may curate list members
        if ($list->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122() && !$user->isAdmin()) {
            return $this->json(['error' => 'Only the list owner or an Admin can remove POIs from this list.'], Response::HTTP_FORBIDDEN);
        }

        $poi->removeList($list);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
