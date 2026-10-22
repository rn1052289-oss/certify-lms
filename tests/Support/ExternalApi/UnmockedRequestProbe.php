<?php

declare(strict_types=1);

namespace Tests\Support\ExternalApi;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stripe\StripeClient;
use Tests\TestCase;

/** RequestGuardTest が別プロセスで実行し、意図した失敗を確認する専用fixture。 */
class UnmockedRequestProbe extends TestCase
{
    public function test_http_fallback_cannot_hide_unmocked_request(): void
    {
        try {
            Http::get('https://unmocked.example.invalid/probe');
        } catch (RuntimeException) {
            // アプリ側がフォールバックして、通信の例外を表へ返さない状況。
        }
    }

    public function test_stripe_fallback_cannot_hide_unmocked_request(): void
    {
        try {
            (new StripeClient('sk_test_guard_probe'))->checkout->sessions->create(['mode' => 'payment']);
        } catch (RuntimeException) {
            // 例外を捕捉しても共通tearDownで失敗する必要がある。
        }
    }
}
