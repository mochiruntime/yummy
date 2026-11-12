<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLinkToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for querying and managing MagicLinkToken entities.
 *
 * @extends ServiceEntityRepository<MagicLinkToken>
 */
class MagicLinkTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLinkToken::class);
    }

    /**
     * Looks up a magic link token by hashing the provided raw token and matching token_hash.
     */
    public function findOneByRawToken(string $rawToken): ?MagicLinkToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }
}
