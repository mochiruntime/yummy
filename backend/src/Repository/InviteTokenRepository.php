<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InviteToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for querying and managing InviteToken entities.
 *
 * @extends ServiceEntityRepository<InviteToken>
 */
class InviteTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InviteToken::class);
    }

    /**
     * Looks up an invite token by hashing the provided raw token and matching token_hash.
     */
    public function findOneByRawToken(string $rawToken): ?InviteToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }

    /**
     * Returns all invites ordered by creation date descending for Admin management views.
     *
     * @return list<InviteToken>
     */
    public function findAllOrderedByCreatedAtDesc(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }
}
