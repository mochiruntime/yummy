<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Passive programmatic authenticator used by Symfony's Security service.
 *
 * In yummy, authentication is established explicitly inside AuthController actions
 * (after validating a magic-link token or invite token) via `$security->login($user, ...)`.
 *
 * Why supports() returns false:
 * This authenticator does not inspect ambient HTTP request headers or query parameters
 * to trigger automatic login. It is only invoked programmatically.
 */
final class MagicLinkAuthenticator extends AbstractAuthenticator
{
    /**
     * Always returns false so ambient requests are not intercepted by this authenticator.
     */
    public function supports(Request $request): ?bool
    {
        return false;
    }

    public function authenticate(Request $request): Passport
    {
        throw new \LogicException('MagicLinkAuthenticator::authenticate() should never be called directly; use Security::login().');
    }

    /**
     * Returning null instructs Symfony not to override or short-circuit the controller response,
     * allowing AuthController to return its own structured JSON response.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(['error' => 'Authentication failed.'], Response::HTTP_UNAUTHORIZED);
    }
}
