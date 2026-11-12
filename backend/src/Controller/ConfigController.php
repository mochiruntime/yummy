<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/api/config', name: 'api_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $googleEnabled = filter_var($_ENV['GOOGLE_MAPS_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $pmtilesUrl = trim((string) ($_ENV['PMTILES_URL'] ?? ''));

        return $this->json([
            'googleMapsEnabled' => $googleEnabled,
            'googleMapsApiKey' => $googleEnabled ? ($_ENV['GOOGLE_MAPS_API_KEY'] ?? null) : null,
            'defaultMapStyleUrl' => (string) ($_ENV['DEFAULT_MAP_STYLE_URL'] ?? 'https://tiles.openfreemap.org/styles/liberty'),
            'pmtilesUrl' => $pmtilesUrl !== '' ? $pmtilesUrl : null,
            'geocoderProvider' => (string) ($_ENV['GEOCODER_PROVIDER'] ?? 'photon'),
            'publicBrowsingEnabled' => filter_var($_ENV['PUBLIC_BROWSING_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
