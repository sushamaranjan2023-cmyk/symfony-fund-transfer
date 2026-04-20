<?php

declare(strict_types=1);

namespace App\Exception;

class AccountNotActiveException extends \DomainException
{
    public function __construct(string $accountId)
    {
        parent::__construct(sprintf('Account "%s" is not active and cannot participate in transfers.', $accountId));
    }
}
