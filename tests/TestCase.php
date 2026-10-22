<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\ExternalApi\RequestGuard;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    private ?RequestGuard $externalRequestGuard = null;

    private ?ClientInterface $originalStripeClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->externalRequestGuard = new RequestGuard;
        $this->externalRequestGuard->guardHttp();
        $this->originalStripeClient = ApiRequestor::httpClient();
        ApiRequestor::setHttpClient($this->externalRequestGuard->stripeClient());
    }

    protected function tearDown(): void
    {
        try {
            $this->externalRequestGuard?->assertNothingBlocked();
        } finally {
            if ($this->originalStripeClient !== null) {
                ApiRequestor::setHttpClient($this->originalStripeClient);
            }
            parent::tearDown();
        }
    }
}
