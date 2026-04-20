<?php

declare(strict_types=1);

namespace App\Exception;

class InsufficientFundsException extends \DomainException
{
    public function __construct(
        string $accountId,
        string $currentBalance,
        string $requestedAmount,
        string $currency,
    ) {
        parent::__construct(sprintf(
            'Account "%s" has balance %s %s; requested transfer of %s %s would cause overdraft.',
            $accountId,
            $currentBalance,
            $currency,
            $requestedAmount,
            $currency
        ));
    }
}
