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
 * Endpoints for querying address and place suggestions.
 */
final class GeocodeController extends AbstractController
{
    /**
     * Resolves address and place suggestions via Photon / Nominatim with DB caching.
     */
    #[Route('/api/geocode', name: 'api_geocode', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function search(Request $request, GeocodingService $geocoder): JsonResponse
    {
        $q = trim($request->query->getString('q', ''));
        if (mb_strlen($q) < 2) {
            return $this->json(['error' => 'Query parameter "q" must be at least 2 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $lat = $request->query->has('lat') ? (float) $request->query->get('lat') : null;
        $lng = $request->query->has('lng') ? (float) $request->query->get('lng') : null;

        $results = $geocoder->search($q, $lat, $lng);

        return $this->json($results);
    }
}
