<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Auth\ClaimInviteDto;
use App\Dto\Auth\CreateInviteDto;
use App\Dto\Auth\MagicLinkRequestDto;
use App\Dto\Auth\MagicLinkVerifyDto;
use App\Entity\User;
use App\Repository\InviteTokenRepository;
use App\Service\AuthTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Handles all authentication, session verification, and friend invite endpoints.
 */
final class AuthController extends AbstractController
{
    /**
     * Returns the currently authenticated user's profile.
     */
    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($user, context: ['groups' => ['user:read']]);
    }

    /**
     * Dispatches a single-use magic-link email to the specified address.
     * Always returns 202 Accepted to prevent user email enumeration.
     */
    #[Route('/api/auth/magic-link', name: 'api_auth_magic_link_request', methods: ['POST'])]
    public function requestMagicLink(
        #[MapRequestPayload] MagicLinkRequestDto $dto,
        Request $request,
        AuthTokenService $authService,
        RateLimiterFactory $magicLinkRequestLimiter,
    ): JsonResponse {
        $limiter = $magicLinkRequestLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many requests. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $authService->requestMagicLink($dto->email);

        return $this->json(['message' => 'If an account exists, a sign-in link has been sent.'], Response::HTTP_ACCEPTED);
    }

    /**
     * Verifies a raw magic-link token and establishes a stateful session cookie.
     */
    #[Route('/api/auth/magic-link/verify', name: 'api_auth_magic_link_verify', methods: ['POST'])]
    public function verifyMagicLink(
        #[MapRequestPayload] MagicLinkVerifyDto $dto,
        AuthTokenService $authService,
        Security $security,
    ): JsonResponse {
        $user = $authService->consumeMagicLink($dto->token);
        if ($user === null) {
            return $this->json(['error' => 'Invalid or expired magic link.'], Response::HTTP_BAD_REQUEST);
        }

        $security->login($user, 'App\\Security\\MagicLinkAuthenticator');

        return $this->json($user, context: ['groups' => ['user:read']]);
    }

    /**
     * Lists all generated friend invites (Admin only).
     */
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
            'status' => $inv->getStatus($now)->value,
        ], $all);

        return $this->json($data);
    }

    /**
     * Creates a new one-time friend invite link (Admin only).
     */
    #[Route('/api/auth/invites', name: 'api_auth_invites_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createInvite(
        #[MapRequestPayload] CreateInviteDto $dto,
        #[CurrentUser] User $admin,
        AuthTokenService $authService,
    ): JsonResponse {
        $created = $authService->createInvite($admin, $dto->expiresInHours);

        return $this->json([
            'id' => $created['invite']->getId()->toRfc4122(),
            'inviteUrl' => $created['inviteUrl'],
            'expiresAt' => $created['invite']->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * Revokes an unused invite token (Admin only).
     */
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

    /**
     * Claims a one-time invite link and registers a new friend account.
     */
    #[Route('/api/auth/invites/{token}/claim', name: 'api_auth_invites_claim', methods: ['POST'])]
    public function claimInvite(
        string $token,
        #[MapRequestPayload] ClaimInviteDto $dto,
        Request $request,
        AuthTokenService $authService,
        Security $security,
        RateLimiterFactory $inviteClaimLimiter,
    ): JsonResponse {
        $limiter = $inviteClaimLimiter->create($request->getClientIp() ?? 'anonymous');
        if (!$limiter->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many attempts.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = $authService->claimInvite($token, $dto->displayName, $dto->email);
        if ($user === null) {
            return $this->json(['error' => 'Invalid or already claimed invite, or email already registered.'], Response::HTTP_BAD_REQUEST);
        }

        $security->login($user, 'App\\Security\\MagicLinkAuthenticator');

        return $this->json($user, Response::HTTP_CREATED, context: ['groups' => ['user:read']]);
    }

    /**
     * Destroys the current authenticated session.
     */
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Security $security): JsonResponse
    {
        $security->logout(false);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
