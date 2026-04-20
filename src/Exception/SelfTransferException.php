<?php

declare(strict_types=1);

namespace App\Exception;

class SelfTransferException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Source and destination accounts must be different.');
    }
}
