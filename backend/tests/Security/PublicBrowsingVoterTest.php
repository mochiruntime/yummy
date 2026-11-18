<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\Voter\PublicBrowsingVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Unit tests for PublicBrowsingVoter firewall access enforcement.
 */
final class PublicBrowsingVoterTest extends TestCase
{
    public function testDeniesGuestsWhenPublicBrowsingDisabled(): void
    {
        $voter = new PublicBrowsingVoter(false);

        $decision = $voter->vote(new NullToken(), null, [PublicBrowsingVoter::ATTR]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $decision);
    }

    public function testAllowsGuestsWhenPublicBrowsingEnabled(): void
    {
        $voter = new PublicBrowsingVoter(true);

        $decision = $voter->vote(new NullToken(), null, [PublicBrowsingVoter::ATTR]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $decision);
    }

    public function testAllowsAuthenticatedUsersEvenWhenPublicBrowsingDisabled(): void
    {
        $voter = new PublicBrowsingVoter(false);
        $user = new User('member@yummy.test', 'Member');
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $decision = $voter->vote($token, null, [PublicBrowsingVoter::ATTR]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $decision);
    }

    public function testAbstainsOnUnsupportedAttribute(): void
    {
        $voter = new PublicBrowsingVoter(false);

        $decision = $voter->vote(new NullToken(), null, ['ROLE_ADMIN']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $decision);
    }
}
