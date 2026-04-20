<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a transfer commits successfully.
 * Consumed asynchronously by TransferCompletedHandler.
 */
final readonly class TransferCompletedMessage
{
    public function __construct(
        public string $transactionId,
    ) {}
}
