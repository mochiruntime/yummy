<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Represents the lifecycle state of a one-time friend invite token.
 */
enum InviteStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
