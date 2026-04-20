<?php

declare(strict_types=1);

namespace App\Exception;

class AccountNotFoundException extends \DomainException
{
    public function __construct(string $accountId)
    {
        parent::__construct(sprintf('Account "%s" was not found.', $accountId));
    }
}
