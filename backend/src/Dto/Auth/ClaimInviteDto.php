<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for claiming a one-time friend invite link.
 */
final class ClaimInviteDto
{
    #[Assert\NotBlank(message: 'Display name is required.')]
    #[Assert\Length(min: 2, max: 60, minMessage: 'Display name must be at least 2 characters.', maxMessage: 'Display name cannot exceed 60 characters.')]
    public string $displayName = '';

    #[Assert\NotBlank(message: 'Email address is required.')]
    #[Assert\Email(message: 'A valid email address is required.')]
    public string $email = '';
}
