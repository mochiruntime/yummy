<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Centralized URL builder for frontend client routes.
 *
 * Prevents ad-hoc string concatenation across services and controllers,
 * ensuring frontend deep links (magic links, invites, POI shares) are consistent
 * and configurable via the injected frontend base URL.
 */
final class FrontendUrlGenerator
{
    private readonly string $baseUrl;

    public function __construct(string $appFrontendUrl)
    {
        $this->baseUrl = rtrim(trim($appFrontendUrl), '/');
    }

    /**
     * Generates a sign-in URL for a raw magic-link token.
     */
    public function generateMagicLinkUrl(string $rawToken): string
    {
        return sprintf('%s/magic-link/%s', $this->baseUrl, urlencode($rawToken));
    }

    /**
     * Generates an invite claim URL for a raw friend-invite token.
     */
    public function generateInviteUrl(string $rawToken): string
    {
        return sprintf('%s/join/%s', $this->baseUrl, urlencode($rawToken));
    }
}
