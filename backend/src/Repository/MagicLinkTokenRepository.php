<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLinkToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLinkToken>
 */
class MagicLinkTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLinkToken::class);
    }

    public function findOneByRawToken(string $rawToken): ?MagicLinkToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }
}
