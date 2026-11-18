<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Normalizes HTTP and validation exceptions on /api/* routes into a consistent {"error": "..."} JSON envelope.
 *
 * Ensures that #[MapRequestPayload] validation failures, invalid route requirements (e.g. non-UUID IDs),
 * access-denied exceptions from voters/IsGranted, and manual HTTP exceptions all share one predictable shape.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    /**
     * Subscribes to KernelEvents::EXCEPTION before Symfony's default ErrorListener.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    /**
     * Formats HttpExceptionInterface instances on /api/* requests as JSON {"error": "..."} responses.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $statusCode = $throwable->getStatusCode();
        $headers = $throwable->getHeaders();
        $previous = $throwable->getPrevious();

        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = [
                    'propertyPath' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                ];
            }

            $firstMessage = $violations[0]['message'] ?? 'Validation failed.';
            $event->setResponse(new JsonResponse(
                [
                    'error' => $firstMessage,
                    'violations' => $violations,
                ],
                $statusCode,
                $headers,
            ));

            return;
        }

        $message = $throwable->getMessage();
        if ($message === '') {
            $message = Response::$statusTexts[$statusCode] ?? 'Request error.';
        }

        $event->setResponse(new JsonResponse(
            ['error' => $message],
            $statusCode,
            $headers,
        ));
    }
}
