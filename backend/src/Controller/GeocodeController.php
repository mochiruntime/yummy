<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Geocode\GeocodeQueryDto;
use App\Service\GeocodingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST API controller for place search and address autocomplete.
 *
 * Security & Rate Limiting:
 * - Gated by `PUBLIC_BROWSING_OR_MEMBER` so guests can search when `PUBLIC_BROWSING_ENABLED=true`,
 *   while private instances (`PUBLIC_BROWSING_ENABLED=false`) return HTTP 401.
 * - Protected by two separate rate limiters (`geocode_public` by client IP, `geocode_member` by user)
 *   before querying upstream Photon/Nominatim quotas.
 * - Deployment note: configure Symfony `framework.trusted_proxies` when behind a reverse proxy
 *   so `$request->getClientIp()` resolves the visitor IP rather than the proxy IP.
 */
final class GeocodeController extends AbstractController
{
    /**
     * Resolves address and place suggestions via backend proxy with database caching.
     *
     * @param GeocodingService   $geocoder             Geocoding service managing Photon/Nominatim and DB cache
     * @param RateLimiterFactory $geocodePublicLimiter Per-IP sliding-window limiter for unauthenticated guests
     * @param RateLimiterFactory $geocodeMemberLimiter Per-user sliding-window limiter for authenticated members
     * @param Request            $request              Current HTTP request (used for client IP resolution)
     * @param GeocodeQueryDto    $query                Validated search query and optional lat/lng bias
     *
     * @throws TooManyRequestsHttpException When the caller exceeds their geocoding rate limit
     */
    #[Route('/api/geocode', name: 'api_geocode', methods: ['GET'])]
    #[IsGranted('PUBLIC_BROWSING_OR_MEMBER')]
    public function search(
        GeocodingService $geocoder,
        RateLimiterFactory $geocodePublicLimiter,
        RateLimiterFactory $geocodeMemberLimiter,
        Request $request,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] GeocodeQueryDto $query = new GeocodeQueryDto(),
    ): JsonResponse {
        $user = $this->getUser();
        $limiter = $user !== null
            ? $geocodeMemberLimiter->create($user->getUserIdentifier())
            : $geocodePublicLimiter->create($request->getClientIp() ?? 'unknown');

        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retryAfter, 'Too many search requests. Please wait a moment.');
        }

        return $this->json($geocoder->search($query->getTrimmedQuery(), $query->lat, $query->lng));
    }
}
