<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\InviteTokenRepository;
use App\Service\AuthTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AuthController extends AbstractController
{
    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->serializeUser($user));
    }

    #[Route('/api/auth/magic-link', name: 'api_auth_magic_link_request', methods: ['POST'])]
    public function requestMagicLink(
        Request $request,
        AuthTokenService $authService,
        RateLimiterFactory $magicLinkRequestLimiter,
    ): JsonResponse {
        $limiter = $magicLinkRequestLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) && isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : '';

        $authService->requestMagicLink($email, $request->getSchemeAndHttpHost());

        // Always return 202 Accepted to prevent user email enumeration
        return $this->json(['message' => 'If an account exists, a sign-in link has been sent.'], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/magic-link/verify', name: 'api_auth_magic_link_verify', methods: ['POST'])]
    public function verifyMagicLink(
        Request $request,
        AuthTokenService $authService,
        Security $security,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) && isset($payload['token']) && is_string($payload['token']) ? trim($payload['token']) : '';

        if ($token === '') {
            return $this->json(['error' => 'Token is required.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $authService->consumeMagicLink($token);
        if ($user === null) {
            return $this->json(['error' => 'Invalid or expired magic link.'], Response::HTTP_BAD_REQUEST);
        }

        $security->login($user, 'App\\Security\\MagicLinkAuthenticator');

        return $this->json($this->serializeUser($user));
    }

    #[Route('/api/auth/invites', name: 'api_auth_invites_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function listInvites(InviteTokenRepository $invites): JsonResponse
    {
        $all = $invites->findAllOrderedByCreatedAtDesc();
        $now = new \DateTimeImmutable();

        $data = array_map(static fn ($inv) => [
            'id' => $inv->getId()->toRfc4122(),
            'createdAt' => $inv->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $inv->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'status' => $inv->getStatus($now),
        ], $all);

        return $this->json($data);
    }

    #[Route('/api/auth/invites', name: 'api_auth_invites_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createInvite(Request $request, AuthTokenService $authService): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $hours = is_array($payload) && isset($payload['expiresInHours']) && is_numeric($payload['expiresInHours'])
            ? (int) $payload['expiresInHours']
            : 168;

        $created = $authService->createInvite($user, $hours);
        $inviteUrl = rtrim($request->getSchemeAndHttpHost(), '/').'/join/'.$created['rawToken'];

        return $this->json([
            'id' => $created['invite']->getId()->toRfc4122(),
            'inviteUrl' => $inviteUrl,
            'expiresAt' => $created['invite']->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/auth/invites/{id}', name: 'api_auth_invites_revoke', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function revokeInvite(
        string $id,
        InviteTokenRepository $invites,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invalid ID.'], Response::HTTP_NOT_FOUND);
        }

        $invite = $invites->find(Uuid::fromString($id));
        if ($invite === null) {
            return $this->json(['error' => 'Invite not found.'], Response::HTTP_NOT_FOUND);
        }

        $invite->revoke();
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/invites/{token}/claim', name: 'api_auth_invites_claim', methods: ['POST'])]
    public function claimInvite(
        string $token,
        Request $request,
        AuthTokenService $authService,
        Security $security,
        RateLimiterFactory $inviteClaimLimiter,
    ): JsonResponse {
        $limiter = $inviteClaimLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many attempts.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $payload = json_decode($request->getContent(), true);
        $displayName = is_array($payload) && isset($payload['displayName']) && is_string($payload['displayName'])
            ? trim($payload['displayName'])
            : '';
        $email = is_array($payload) && isset($payload['email']) && is_string($payload['email'])
            ? trim($payload['email'])
            : '';

        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 60) {
            return $this->json(['error' => 'Display name must be between 2 and 60 characters.'], Response::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'A valid email address is required.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $authService->claimInvite($token, $displayName, $email);
        if ($user === null) {
            return $this->json(['error' => 'Invalid or already claimed invite, or email already registered.'], Response::HTTP_BAD_REQUEST);
        }

        $security->login($user, 'App\\Security\\MagicLinkAuthenticator');

        return $this->json($this->serializeUser($user), Response::HTTP_CREATED);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Security $security): JsonResponse
    {
        $security->logout(false);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: string, email: string, displayName: string, roles: list<string>}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles(),
        ];
    }
}
