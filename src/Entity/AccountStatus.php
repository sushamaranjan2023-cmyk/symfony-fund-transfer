<?php

declare(strict_types=1);

namespace App\Entity;

enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';
}
