<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InviteToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InviteToken>
 */
class InviteTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InviteToken::class);
    }

    public function findOneByRawToken(string $rawToken): ?InviteToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }

    /**
     * @return list<InviteToken>
     */
    public function findAllOrderedByCreatedAtDesc(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC']);
    }
}
