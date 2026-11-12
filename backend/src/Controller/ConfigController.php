<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exposes non-sensitive instance bootstrap configuration to the frontend SPA.
 *
 * All settings are injected via Symfony DI parameter binding rather than
 * reading superglobals directly.
 */
final class ConfigController extends AbstractController
{
    public function __construct(
        private readonly bool $googleMapsEnabled,
        private readonly string $googleMapsApiKey,
        private readonly string $defaultMapStyleUrl,
        private readonly string $pmtilesUrl,
        private readonly string $geocoderProvider,
        private readonly bool $publicBrowsingEnabled,
    ) {
    }

    /**
     * Bootstrap configuration endpoint fetched by the React SPA before map initialization.
     */
    #[Route('/api/config', name: 'api_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $pmtiles = trim($this->pmtilesUrl);

        return $this->json([
            'googleMapsEnabled' => $this->googleMapsEnabled,
            'googleMapsApiKey' => $this->googleMapsEnabled && $this->googleMapsApiKey !== '' ? $this->googleMapsApiKey : null,
            'defaultMapStyleUrl' => $this->defaultMapStyleUrl,
            'pmtilesUrl' => $pmtiles !== '' ? $pmtiles : null,
            'geocoderProvider' => $this->geocoderProvider,
            'publicBrowsingEnabled' => $this->publicBrowsingEnabled,
        ]);
    }
}
