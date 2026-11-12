<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for creating a one-time friend invite link.
 */
final class CreateInviteDto
{
    #[Assert\Positive(message: 'Expiration hours must be greater than zero.')]
    #[Assert\LessThanOrEqual(value: 720, message: 'Expiration cannot exceed 30 days (720 hours).')]
    public int $expiresInHours = 168; // Default: 7 days
}
