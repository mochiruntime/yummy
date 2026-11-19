<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Feed\FeedQueryDto;
use App\Entity\User;
use App\Service\ActivityFeedService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * REST API controller for the chronological, visibility-filtered activity feed.
 */
final class FeedController extends AbstractController
{
    /**
     * Returns a cursor-paginated page of activity events filtered by viewer permissions.
     *
     * @param FeedQueryDto        $query       Validated pagination query parameters
     * @param ActivityFeedService $feedService Activity feed query and assembly service
     * @param User|null           $user        Authenticated user, or null for guests
     */
    #[Route('/api/feed', name: 'api_feed_index', methods: ['GET'])]
    public function index(
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] FeedQueryDto $query,
        ActivityFeedService $feedService,
        #[CurrentUser] ?User $user = null,
    ): JsonResponse {
        return $this->json($feedService->getFeed($user, $query->limit, $query->toCursor()));
    }
}
