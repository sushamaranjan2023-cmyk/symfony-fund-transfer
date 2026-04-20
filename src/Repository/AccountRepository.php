<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Acquire a PESSIMISTIC WRITE lock on the account row.
     * Translates to: SELECT ... FOR UPDATE in MySQL.
     *
     * IMPORTANT: The caller MUST be inside an active Doctrine transaction.
     * Calling this outside a transaction will throw a TransactionRequiredException.
     */
    public function findWithPessimisticLock(string $id): ?Account
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Find multiple accounts with pessimistic locks in a single query.
     * Sorts by ID to ensure consistent lock ordering and prevent deadlocks.
     */
    public function findMultipleWithPessimisticLock(array $ids): array
    {
        sort($ids); // Consistent ordering prevents AB/BA deadlocks

        return $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }
}
