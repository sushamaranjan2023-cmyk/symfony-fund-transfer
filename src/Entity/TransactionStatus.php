<?php

declare(strict_types=1);

namespace App\Entity;

enum TransactionStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Reversed  = 'reversed';
}
