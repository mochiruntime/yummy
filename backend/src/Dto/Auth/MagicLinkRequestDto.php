<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for requesting a magic-link sign-in email.
 */
final class MagicLinkRequestDto
{
    #[Assert\NotBlank(message: 'Email address is required.')]
    #[Assert\Email(message: 'A valid email address is required.')]
    public string $email = '';
}
