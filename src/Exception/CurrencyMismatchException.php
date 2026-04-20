<?php

declare(strict_types=1);

namespace App\Exception;

class CurrencyMismatchException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Source account, destination account, and requested currency must all match. '
            . 'Cross-currency transfers require an explicit FX conversion step.'
        );
    }
}
