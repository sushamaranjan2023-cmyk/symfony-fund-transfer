<?php

declare(strict_types=1);

namespace App\Exception;

class DuplicateTransferException extends \RuntimeException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(sprintf(
            'A transfer with idempotency key "%s" is already being processed.',
            $idempotencyKey
        ));
    }
}
