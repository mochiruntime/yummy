<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InviteTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InviteTokenRepository::class)]
#[ORM\Table(name: 'invite_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_invite_token_hash', columns: ['token_hash'])]
class InviteToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'claimed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $claimedBy = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenHash, User $createdBy, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->createdBy = $createdBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getClaimedBy(): ?User
    {
        return $this->claimedBy;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStatus(\DateTimeImmutable $now = new \DateTimeImmutable()): string
    {
        if ($this->claimedAt !== null) {
            return 'claimed';
        }
        if ($this->revokedAt !== null) {
            return 'revoked';
        }
        if ($this->expiresAt <= $now) {
            return 'expired';
        }

        return 'pending';
    }

    public function isClaimable(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->getStatus($now) === 'pending';
    }

    public function markClaimed(User $user, \DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->claimedBy = $user;
        $this->claimedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->revokedAt = $now;
    }
}
