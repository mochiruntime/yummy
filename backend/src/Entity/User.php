<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Represents a registered member or admin of the yummy instance.
 *
 * Authentication is passwordless via single-use email magic links or
 * initial claim of a one-time friend invite link.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface
{
    /**
     * Unique identifier using time-ordered UUIDv7.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['user:read'])]
    private Uuid $id;

    /**
     * Normalized lowercase email address used for magic-link dispatch and authentication.
     */
    #[ORM\Column(length: 180)]
    #[Groups(['user:read'])]
    private string $email;

    /**
     * Public display name shown to other members on POIs, reviews, and comments.
     */
    #[ORM\Column(name: 'display_name', length: 60)]
    #[Groups(['user:read'])]
    #[SerializedName('displayName')]
    private string $displayName;

    /**
     * Security roles: ROLE_USER (standard friend member) and/or ROLE_ADMIN (instance manager).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['user:read'])]
    private array $roles = ['ROLE_USER'];

    /**
     * Timestamp when the user account was provisioned.
     */
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['user:read'])]
    #[SerializedName('createdAt')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string       $email       User's email address (normalized to lowercase)
     * @param string       $displayName Public display name
     * @param list<string> $roles       Assigned security roles (ROLE_USER is always guaranteed)
     */
    public function __construct(string $email, string $displayName, array $roles = ['ROLE_USER'])
    {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower(trim($email));
        $this->displayName = trim($displayName);
        $this->roles = array_values(array_unique([...$roles, 'ROLE_USER']));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Required by Symfony's UserInterface as the unique principal identifier.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        $id = $this->email !== '' ? $this->email : $this->id->toRfc4122();
        \assert($id !== '');

        return $id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = trim($displayName);
    }

    /**
     * Returns all assigned roles, always ensuring ROLE_USER is present.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique([...$roles, 'ROLE_USER']));
    }

    /**
     * Helper to check whether this user holds administrative privileges.
     */
    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Required by UserInterface for cleaning up sensitive credentials.
     * Since yummy is passwordless, there are no plaintext credentials stored on the entity.
     */
    public function eraseCredentials(): void
    {
    }
}
