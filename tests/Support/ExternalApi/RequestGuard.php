<?php

declare(strict_types=1);

namespace Tests\Support\ExternalApi;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Stripe\HttpClient\ClientInterface;

/** モック漏れを通信前に止め、アプリが例外を握りつぶしてもテストを失敗させる。 */
final class RequestGuard
{
    private int $blocked = 0;

    public function guardHttp(): void
    {
        Http::preventStrayRequests();
        Http::globalMiddleware(function (callable $handler) {
            return function ($request, array $options) use ($handler) {
                try {
                    return $handler($request, $options);
                } catch (RuntimeException $exception) {
                    if (str_starts_with($exception->getMessage(), 'Attempted request to [')) {
                        $this->block('HTTP');
                    }
                    throw $exception;
                }
            };
        });
    }

    public function stripeClient(): ClientInterface
    {
        return new class($this) implements ClientInterface
        {
            public function __construct(private readonly RequestGuard $guard) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->guard->block('Stripe');
            }
        };
    }

    public function block(string $transport): never
    {
        $this->blocked++;
        // URL・headers・本文にはテスト環境でも秘密が入り得るため、記録しない。
        throw new RuntimeException('Unmocked '.$transport.' request blocked.');
    }

    public function assertNothingBlocked(): void
    {
        Assert::assertSame(0, $this->blocked, 'モックされていない外部通信が発生しました。');
    }
}
