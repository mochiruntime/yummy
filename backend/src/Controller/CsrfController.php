<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues or refreshes the double-submit CSRF cookie (XSRF-TOKEN) for the SPA.
 *
 * In single-page applications using cookie-based session authentication,
 * the double-submit cookie pattern allows JavaScript to read the cookie value
 * and reflect it in the X-XSRF-TOKEN request header on state-changing requests,
 * preventing cross-site request forgery without requiring server-side token storage.
 */
final class CsrfController extends AbstractController
{
    /**
     * Issues an un-HttpOnly XSRF-TOKEN cookie readable by JavaScript with SameSite=Lax.
     */
    #[Route('/api/auth/csrf', name: 'api_auth_csrf', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        // 256-bit CSPRNG token for double-submit CSRF protection
        $token = bin2hex(random_bytes(32));

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie(
            Cookie::create('XSRF-TOKEN')
                ->withValue($token)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false) // Must be readable by the React SPA to send in X-XSRF-TOKEN header
                ->withSameSite(Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
