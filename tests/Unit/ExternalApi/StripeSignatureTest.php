<?php

declare(strict_types=1);

namespace Tests\Unit\ExternalApi;

use PHPUnit\Framework\Attributes\Group;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Tests\Support\ExternalApi\StripeSignature;
use Tests\TestCase;

#[Group('external-api')]
class StripeSignatureTest extends TestCase
{
    public function test_helper_signs_the_original_unicode_payload_for_the_real_verifier(): void
    {
        $payload = '{ "id": "evt_local", "object": "event", "data": {"object": {"label": "面談"}} }';
        $event = Webhook::constructEvent($payload, StripeSignature::make($payload, 'whsec_test'), 'whsec_test');
        $this->assertSame('evt_local', $event->id);
        $this->assertSame('面談', $event->data->object->label);
    }

    public function test_reencoding_the_same_json_invalidates_the_original_signature(): void
    {
        $payload = '{ "id": "evt_local", "object": "event" }';
        $signature = StripeSignature::make($payload, 'whsec_test');
        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent(json_encode(json_decode($payload, true)), $signature, 'whsec_test');
    }
}
