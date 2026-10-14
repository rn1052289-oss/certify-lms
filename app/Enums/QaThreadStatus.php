<?php

declare(strict_types=1);

namespace App\Enums;

enum QaThreadStatus: string
{
    case Unresolved = 'unresolved';
    case Resolved = 'resolved';
}
