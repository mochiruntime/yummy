<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces double-submit CSRF protection (XSRF-TOKEN cookie === X-XSRF-TOKEN header)
 * on all state-changing /api/ requests.
 */
final class CsrfRequestSubscriber implements EventSubscriberInterface
{
    private const STATE_CHANGING_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if (!in_array($request->getMethod(), self::STATE_CHANGING_METHODS, true)) {
            return;
        }

        $cookieToken = $request->cookies->get('XSRF-TOKEN');
        $headerToken = $request->headers->get('X-XSRF-TOKEN');

        if (!is_string($cookieToken) || $cookieToken === '' || !is_string($headerToken) || !hash_equals($cookieToken, $headerToken)) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid or missing CSRF token.'], Response::HTTP_FORBIDDEN));
        }
    }
}
