<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CsrfController extends AbstractController
{
    #[Route('/api/auth/csrf', name: 'api_auth_csrf', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $token = bin2hex(random_bytes(32));
        $response = new Response(status: Response::HTTP_NO_CONTENT);
        $response->headers->setCookie(
            Cookie::create('XSRF-TOKEN')
                ->withValue($token)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX)
        );

        return $response;
    }
}
