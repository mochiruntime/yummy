<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GeocodingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * @param Request          $request  Current HTTP request containing 'q', and optional 'lat'/'lng'
     * @param GeocodingService $geocoder Geocoding service managing Photon/Nominatim and DB cache
     */
    #[Route('/api/geocode', name: 'api_geocode', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function search(Request $request, GeocodingService $geocoder): JsonResponse
    {
        $q = trim($request->query->getString('q', ''));

        // Upstream providers (Photon & Nominatim) require at least 2 characters for meaningful search queries
        if (mb_strlen($q) < 2) {
            return $this->json(['error' => 'Query parameter "q" must be at least 2 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $lat = $request->query->has('lat') ? (float) $request->query->get('lat') : null;
        $lng = $request->query->has('lng') ? (float) $request->query->get('lng') : null;

        $results = $geocoder->search($q, $lat, $lng);

        return $this->json($results);
    }
}
