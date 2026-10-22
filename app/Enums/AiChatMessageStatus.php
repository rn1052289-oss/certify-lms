<?php

declare(strict_types=1);

namespace App\Enums;

enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
