<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Geocode\GeocodeQueryDto;
use App\Service\GeocodingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * REST API controller for place search and address autocomplete.
 *
 * Security:
 * Requires authenticated access (IS_AUTHENTICATED_FULLY) to prevent public crawlers or
 * unauthenticated web traffic from consuming upstream OpenStreetMap / Photon quotas.
 */
final class GeocodeController extends AbstractController
{
    /**
     * Resolves address and place suggestions via backend proxy with database caching.
     *
     * @param GeocodingService $geocoder Geocoding service managing Photon/Nominatim and DB cache
     * @param GeocodeQueryDto  $query    Validated search query and optional lat/lng bias
     */
    #[Route('/api/geocode', name: 'api_geocode', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function search(
        GeocodingService $geocoder,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)] GeocodeQueryDto $query = new GeocodeQueryDto(),
    ): JsonResponse {
        return $this->json($geocoder->search($query->getTrimmedQuery(), $query->lat, $query->lng));
    }
}
