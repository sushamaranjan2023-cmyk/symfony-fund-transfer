<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\TransferRequest;
use App\Entity\Transaction;
use App\Entity\TransactionStatus;
use App\Exception\AccountNotFoundException;
use App\Exception\AccountNotActiveException;
use App\Exception\CurrencyMismatchException;
use App\Exception\DuplicateTransferException;
use App\Exception\SelfTransferException;
use App\Message\TransferCompletedMessage;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

final class TransferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository      $accountRepo,
        private readonly IdempotencyService     $idempotency,
        private readonly DistributedLockService $lockService,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $auditLogger,
    ) {}

    /**
     * Execute a fund transfer.
     *
     * Flow:
     *   1. Idempotency check — deduplicate via Redis
     *   2. Distributed Redis lock — serialise at application layer
     *   3. DB transaction + SELECT FOR UPDATE — serialise at data layer
     *   4. Business rule validation (status, currency, balance)
     *   5. Atomic debit + credit using bcmath
     *   6. Persist Transaction record
     *   7. Structured audit log
     *   8. Async post-commit notification via Messenger
     */
    public function execute(TransferRequest $dto, UserInterface $user, ?string $ipAddress = null): array
    {
        // ── Guard: self-transfer ────────────────────────────────────────────
        if ($dto->isSelfTransfer()) {
            throw new SelfTransferException();
        }

        // ── 1. Idempotency check ────────────────────────────────────────────
        $cached = $this->idempotency->check($dto->idempotencyKey);

        if ($cached === null) {
            // Another worker is currently processing this exact request
            throw new DuplicateTransferException($dto->idempotencyKey);
        }

        if (is_array($cached)) {
            // Already completed — return the original response (idempotent replay)
            $this->auditLogger->info('transfer.idempotent_replay', [
                'idempotency_key' => $dto->idempotencyKey,
                'initiated_by'    => $user->getUserIdentifier(),
            ]);
            return $cached;
        }

        // Mark IN_PROGRESS atomically (SET NX); if another worker beat us, bail
        if (!$this->idempotency->markInProgress($dto->idempotencyKey)) {
            throw new DuplicateTransferException($dto->idempotencyKey);
        }

        // ── 2. Distributed lock ─────────────────────────────────────────────
        $lockKey   = DistributedLockService::accountPairKey(
            $dto->sourceAccountId,
            $dto->destinationAccountId
        );
        $lockToken = $this->lockService->acquire($lockKey);

        if ($lockToken === false) {
            $this->idempotency->markFailed($dto->idempotencyKey);
            throw new \RuntimeException(
                'Could not acquire transfer lock. The accounts are busy; please retry in a moment.'
            );
        }

        try {
            $response = $this->runInTransaction($dto, $user, $ipAddress);
            $this->idempotency->markComplete($dto->idempotencyKey, $response);
            return $response;
        } catch (\Throwable $e) {
            // Release idempotency key so caller can retry on transient errors
            $this->idempotency->markFailed($dto->idempotencyKey);
            throw $e;
        } finally {
            $this->lockService->release($lockKey, $lockToken);
        }
    }

    private function runInTransaction(TransferRequest $dto, UserInterface $user, ?string $ipAddress): array
    {
        return $this->em->wrapInTransaction(function () use ($dto, $user, $ipAddress) {

            // ── 3. Load accounts with PESSIMISTIC WRITE lock ────────────────
            // Sorted by ID (done inside findMultipleWithPessimisticLock) to prevent
            // deadlocks when two concurrent transfers involve the same pair of accounts.
            $accounts = $this->accountRepo->findMultipleWithPessimisticLock([
                $dto->sourceAccountId,
                $dto->destinationAccountId,
            ]);

            $indexed = [];
            foreach ($accounts as $account) {
                $indexed[(string) $account->getId()] = $account;
            }

            $source = $indexed[$dto->sourceAccountId] ?? null;
            $dest   = $indexed[$dto->destinationAccountId] ?? null;

            if ($source === null) {
                throw new AccountNotFoundException($dto->sourceAccountId);
            }
            if ($dest === null) {
                throw new AccountNotFoundException($dto->destinationAccountId);
            }

            // ── 4. Business rule validation ─────────────────────────────────
            if (!$source->isActive()) {
                throw new AccountNotActiveException($dto->sourceAccountId);
            }
            if (!$dest->isActive()) {
                throw new AccountNotActiveException($dto->destinationAccountId);
            }
            if (
                strtoupper($source->getCurrency()) !== strtoupper($dto->currency) ||
                strtoupper($dest->getCurrency())   !== strtoupper($dto->currency)
            ) {
                throw new CurrencyMismatchException();
            }

            // ── 5. Debit + Credit — bcmath only, never floats ───────────────
            $source->debit($dto->amount);   // throws InsufficientFundsException if balance < amount
            $dest->credit($dto->amount);

            // ── 6. Persist Transaction record ───────────────────────────────
            $transaction = new Transaction(
                id:             (string) Uuid::v7(),  // time-ordered UUID — keeps index clustered
                idempotencyKey: $dto->idempotencyKey,
                sourceAccount:  $source,
                destAccount:    $dest,
                amount:         $dto->amount,
                currency:       strtoupper($dto->currency),
                status:         TransactionStatus::Completed,
                initiatedBy:    $user->getUserIdentifier(),
                ipAddress:      $ipAddress,
            );

            $this->em->persist($transaction);
            // em->flush() is called automatically by wrapInTransaction on commit

            // ── 7. Structured audit log ─────────────────────────────────────
            $this->auditLogger->info('transfer.completed', [
                'transaction_id'  => (string) $transaction->getId(),
                'source_account'  => $dto->sourceAccountId,
                'dest_account'    => $dto->destinationAccountId,
                'amount'          => $dto->amount,
                'currency'        => strtoupper($dto->currency),
                'initiated_by'    => $user->getUserIdentifier(),
                'idempotency_key' => $dto->idempotencyKey,
                'ip_address'      => $ipAddress,
            ]);

            // ── 8. Dispatch async event (fires AFTER commit) ────────────────
            $this->bus->dispatch(
                new TransferCompletedMessage((string) $transaction->getId())
            );

            return [
                'transaction_id' => (string) $transaction->getId(),
                'status'         => 'completed',
                'amount'         => $dto->amount,
                'currency'       => strtoupper($dto->currency),
                'source_account' => $dto->sourceAccountId,
                'dest_account'   => $dto->destinationAccountId,
                'completed_at'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        });
    }
}
