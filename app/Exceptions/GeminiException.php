<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class GeminiException extends RuntimeException
{
    public function __construct(public readonly ?int $upstreamStatus = null)
    {
        parent::__construct('AI が応答できませんでした。しばらく時間をおいて再試行してください。');
    }
}
