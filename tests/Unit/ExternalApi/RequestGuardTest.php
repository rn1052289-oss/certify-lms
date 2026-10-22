<?php

declare(strict_types=1);

namespace Tests\Unit\ExternalApi;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\StripeClient;
use Symfony\Component\Process\Process;
use Tests\Support\ExternalApi\RequestGuard;
use Tests\TestCase;

#[Group('external-api')]
class RequestGuardTest extends TestCase
{
    public function test_swallowed_unmocked_http_request_still_fails_the_guard(): void
    {
        $original = Http::getFacadeRoot();
        $guard = new RequestGuard;
        Http::swap(new Factory);
        try {
            $guard->guardHttp();
            try {
                Http::get('https://unmocked.example.invalid/private?key=test-value');
                $this->fail('モック漏れの通信が止まりませんでした。');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unmocked HTTP request blocked.', $exception->getMessage());
            }
        } finally {
            Http::swap($original);
        }
        $this->expectException(AssertionFailedError::class);
        $guard->assertNothingBlocked();
    }

    public function test_swallowed_unmocked_stripe_request_still_fails_the_guard(): void
    {
        $original = ApiRequestor::httpClient();
        $guard = new RequestGuard;
        ApiRequestor::setHttpClient($guard->stripeClient());
        try {
            try {
                (new StripeClient('sk_test_guard_probe'))->checkout->sessions->create(['mode' => 'payment']);
                $this->fail('SDK のモック漏れが止まりませんでした。');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unmocked Stripe request blocked.', $exception->getMessage());
            }
        } finally {
            ApiRequestor::setHttpClient($original);
        }
        $this->expectException(AssertionFailedError::class);
        $guard->assertNothingBlocked();
    }

    public function test_common_teardown_fails_swallowed_requests_in_a_separate_process(): void
    {
        $report = tempnam(sys_get_temp_dir(), 'ta04-guard-');
        try {
            $process = new Process([
                PHP_BINARY, base_path('vendor/bin/phpunit'), '-c', base_path('phpunit.xml'),
                base_path('tests/Support/ExternalApi/UnmockedRequestProbe.php'), '--log-junit', $report,
            ], base_path());
            $process->setTimeout(30)->run();
            $this->assertFalse($process->isSuccessful());
            $xml = simplexml_load_file($report);
            $this->assertNotFalse($xml, $process->getErrorOutput());
            $failures = $xml->xpath('//testcase/failure');
            $this->assertCount(2, $failures, $process->getOutput());
            foreach ($failures as $failure) {
                $this->assertStringContainsString('モックされていない外部通信が発生しました。', (string) $failure);
            }
        } finally {
            unlink($report);
        }
    }

    public function test_matching_http_fake_is_allowed(): void
    {
        Http::fake(['https://mocked.example.invalid/ok' => Http::response(['ok' => true])]);
        $this->assertTrue(Http::get('https://mocked.example.invalid/ok')->json('ok'));
        Http::assertSentCount(1);
    }
}
