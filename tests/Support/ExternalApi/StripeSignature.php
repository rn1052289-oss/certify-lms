<?php

declare(strict_types=1);

namespace Tests\Support\ExternalApi;

final class StripeSignature
{
    public static function make(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
