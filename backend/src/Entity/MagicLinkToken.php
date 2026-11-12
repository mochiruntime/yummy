<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MagicLinkTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a short-lived (15-minute) single-use magic-link sign-in token.
 *
 * Security Model:
 * 1. Generated using 256 bits of CSPRNG entropy via random_bytes(32).
 * 2. Only the SHA-256 hash of the token is stored in the database.
 * 3. Invalidation occurs immediately on first consumption, or upon expiration.
 */
#[ORM\Entity(repositoryClass: MagicLinkTokenRepository::class)]
#[ORM\Table(name: 'magic_link_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_magic_link_token_hash', columns: ['token_hash'])]
class MagicLinkToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * User account associated with this sign-in link.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * SHA-256 hash of the raw magic-link token (64 hex characters).
     */
    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    /**
     * Timestamp after which this magic link is no longer valid (default 15m).
     */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    /**
     * Timestamp when the token was consumed. Null if pending.
     */
    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param User               $user      Target user receiving the link
     * @param string             $tokenHash SHA-256 hash of the raw token
     * @param \DateTimeImmutable $expiresAt Expiration timestamp
     */
    public function __construct(User $user, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Checks if this token is unconsumed and within its expiration window.
     */
    public function isUsable(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->usedAt === null && $this->expiresAt > $now;
    }

    /**
     * Marks the token as consumed.
     */
    public function markUsed(\DateTimeImmutable $now = new \DateTimeImmutable()): void
    {
        $this->usedAt = $now;
    }
}
