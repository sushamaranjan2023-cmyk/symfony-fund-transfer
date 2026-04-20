<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function findByIdempotencyKey(string $key): ?Transaction
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    /**
     * Returns the last N transactions for an account (source or dest).
     */
    public function findRecentForAccount(string $accountId, int $limit = 20): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.sourceAccountId = :id OR t.destAccountId = :id')
            ->setParameter('id', $accountId)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
