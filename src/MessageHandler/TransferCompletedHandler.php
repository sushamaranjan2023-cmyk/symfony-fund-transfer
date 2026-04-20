<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\TransferCompletedMessage;
use App\Repository\TransactionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles post-commit side-effects for completed transfers.
 * Runs asynchronously in the worker process — NOT in the HTTP request lifecycle.
 *
 * Add: email notifications, webhook calls, reconciliation triggers, etc.
 */
#[AsMessageHandler]
final class TransferCompletedHandler
{
    public function __construct(
        private readonly TransactionRepository $transactionRepo,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(TransferCompletedMessage $message): void
    {
        $transaction = $this->transactionRepo->find($message->transactionId);

        if ($transaction === null) {
            $this->logger->error('transfer.handler.transaction_not_found', [
                'transaction_id' => $message->transactionId,
            ]);
            return;
        }

        $this->logger->info('transfer.handler.processing', [
            'transaction_id' => $message->transactionId,
            'amount'         => $transaction->getAmount(),
            'currency'       => $transaction->getCurrency(),
        ]);

        // TODO: Add email notification service call
        // TODO: Add webhook delivery service call
        // TODO: Add reconciliation balance check

        $this->logger->info('transfer.handler.completed', [
            'transaction_id' => $message->transactionId,
        ]);
    }
}
