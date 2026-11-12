<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for verifying a magic-link sign-in token.
 */
final class MagicLinkVerifyDto
{
    #[Assert\NotBlank(message: 'Token is required.')]
    #[Assert\Length(min: 32, max: 128, minMessage: 'Invalid token format.')]
    public string $token = '';
}
