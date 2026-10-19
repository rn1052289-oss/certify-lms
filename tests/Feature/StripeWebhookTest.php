<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Services\MeetingQuotaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'whsec_local_signature_test']);
    }

    public function test_signed_paid_event_atomically_adds_snapshot_quota_once_across_repeated_and_different_events(): void
    {
        $payment = Payment::factory()->create();
        $user = $payment->user;
        $originalMax = $user->max_meetings;
        $before = app(MeetingQuotaService::class)->remaining($user);
        $payment->meetingPack->update(['price' => 1, 'meeting_count' => 99]);

        $this->sendEvent($this->event($payment))->assertOk();
        $this->sendEvent($this->event($payment))->assertOk();
        $this->sendEvent($this->event($payment, ['id' => 'evt_another', 'type' => 'checkout.session.async_payment_succeeded']))->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame($before + $payment->quantity, app(MeetingQuotaService::class)->remaining($user));
        $this->assertSame($originalMax, $user->fresh()->max_meetings);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $user->id, 'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased->value, 'amount' => $payment->quantity,
        ]);
        $this->actingAs($user)->get(route('meeting-quota.history'))->assertOk()->assertSee($payment->meetingPack->name);
    }

    public function test_invalid_missing_stale_and_tampered_signatures_never_grant_quota(): void
    {
        $payment = Payment::factory()->create();
        $payload = json_encode($this->event($payment));
        foreach (['', 't=1,v1=invalid', $this->signature($payload, time() - 600), $this->signature($payload.'tampered')] as $signature) {
            $this->call('POST', route('webhooks.stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        }
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unconfigured_webhook_rejects_event(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $this->sendEvent($this->event(Payment::factory()->create()))->assertStatus(503);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_signed_unknown_or_missing_local_payment_events_are_ignored(): void
    {
        $payment = Payment::factory()->create();
        $this->sendEvent($this->event($payment, ['type' => 'customer.created']))->assertOk();
        $event = $this->event($payment);
        $event['data']['object']['metadata']['payment_id'] = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->sendEvent($event)->assertOk();
        $event['data']['object']['metadata'] = [];
        $this->sendEvent($event)->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_owner_amount_currency_session_reference_mode_and_intent_mismatch_are_rejected(): void
    {
        $payment = Payment::factory()->create();
        foreach ([
            ['amount_total' => 1], ['currency' => 'usd'], ['client_reference_id' => 'other'],
            ['id' => 'cs_test_other'], ['mode' => 'subscription'], ['payment_intent' => null],
            ['metadata' => ['payment_id' => $payment->id, 'user_id' => 'other']],
        ] as $changes) {
            $this->sendEvent($this->event($payment, [], $changes))->assertStatus(400);
        }
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unpaid_complete_event_stays_pending_until_paid_notification(): void
    {
        $payment = Payment::factory()->create();
        $this->sendEvent($this->event($payment, [], ['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->sendEvent($this->event($payment, ['type' => 'checkout.session.async_payment_succeeded']))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_expired_and_failed_events_do_not_add_quota_or_revert_succeeded_payment(): void
    {
        foreach (['checkout.session.expired', 'checkout.session.async_payment_failed'] as $type) {
            $payment = Payment::factory()->create();
            $this->sendEvent($this->event($payment, ['type' => $type], ['payment_status' => 'unpaid', 'status' => 'expired']))->assertOk();
            $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        }
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->sendEvent($this->event($payment))->assertOk();
        $this->sendEvent($this->event($payment, ['type' => 'checkout.session.expired'], ['payment_status' => 'unpaid', 'status' => 'expired']))->assertOk();
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_normal_payment_is_applied_after_graduation_or_soft_withdrawal_without_unlocking_access(): void
    {
        foreach ([UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $payment = Payment::factory()->create();
            $user = $payment->user;
            $user->update(['status' => $status]);
            if ($status === UserStatus::Withdrawn) {
                $user->delete();
            }
            $this->sendEvent($this->event($payment))->assertOk();
            $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
            $this->assertDatabaseHas('meeting_quota_transactions', ['related_payment_id' => $payment->id, 'amount' => 3]);
            $this->assertSame($status, $payment->fresh()->user->status);
            $this->actingAs($user)->get(route('meeting-quota.checkout.select'))->assertForbidden();
        }
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
    }

    public function test_webhook_arriving_before_checkout_response_can_identify_payment_from_snapshot(): void
    {
        $payment = Payment::factory()->create(['stripe_checkout_session_id' => null]);
        $this->sendEvent($this->event($payment, [], ['id' => 'cs_test_arrived_first']))->assertOk();
        $this->assertSame('cs_test_arrived_first', $payment->fresh()->stripe_checkout_session_id);
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_quota_insert_failure_rolls_back_payment_confirmation_and_allows_retry(): void
    {
        $payment = Payment::factory()->create();
        $initialDispatcher = MeetingQuotaTransaction::getEventDispatcher();
        $dispatcher = clone $initialDispatcher;
        MeetingQuotaTransaction::setEventDispatcher($dispatcher);
        $dispatcher->listen('eloquent.creating: '.MeetingQuotaTransaction::class, function () {
            throw new RuntimeException('simulated quota insert failure');
        });
        try {
            $this->withoutExceptionHandling();
            $this->sendEvent($this->event($payment));
            $this->fail('付与履歴の保存失敗が検出されませんでした。');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated quota insert failure', $e->getMessage());
        } finally {
            MeetingQuotaTransaction::setEventDispatcher($initialDispatcher);
            $this->withExceptionHandling();
        }
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->stripe_payment_intent_id);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        $this->sendEvent($this->event($payment))->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_same_stripe_intent_cannot_credit_two_different_local_payments(): void
    {
        $first = Payment::factory()->create();
        $second = Payment::factory()->create();
        $this->sendEvent($this->event($first))->assertOk();
        $this->sendEvent($this->event($second, [], ['payment_intent' => 'pi_test_'.$first->id]))->assertStatus(500);
        $this->assertSame(PaymentStatus::Pending, $second->fresh()->status);
        $this->assertNull($second->fresh()->paid_at);
        $this->assertDatabaseCount('meeting_quota_transactions', 1);
    }

    public function test_invalid_json_with_valid_signature_and_malformed_known_event_are_safe(): void
    {
        $payload = '{malformed';
        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->signature($payload), 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);
        $this->sendEvent(['object' => 'event', 'type' => 'checkout.session.completed', 'data' => []])->assertOk();
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_only_webhook_is_excluded_from_csrf_and_valid_signature_still_required(): void
    {
        $this->app['env'] = 'local';
        $payment = Payment::factory()->create();
        $this->actingAs($payment->user)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $payment->meeting_pack_id])->assertStatus(419);
        $this->sendEvent($this->event($payment))->assertOk();
        $this->call('POST', route('webhooks.stripe'), [], [], [], [], '{}')->assertStatus(400);
    }

    public function test_database_unique_constraint_prevents_second_quota_record_for_same_payment(): void
    {
        $payment = Payment::factory()->create();
        $this->sendEvent($this->event($payment))->assertOk();
        $this->expectException(QueryException::class);
        MeetingQuotaTransaction::create([
            'user_id' => $payment->user_id, 'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::Purchased, 'amount' => 3, 'occurred_at' => now(),
        ]);
    }

    private function event(Payment $payment, array $eventChanges = [], array $sessionChanges = []): array
    {
        return array_replace_recursive([
            'id' => 'evt_test_paid', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => array_replace([
                'id' => $payment->stripe_checkout_session_id, 'object' => 'checkout.session',
                'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
                'payment_intent' => 'pi_test_'.$payment->id,
                'client_reference_id' => $payment->id, 'amount_total' => $payment->amount, 'currency' => $payment->currency,
                'metadata' => ['payment_id' => $payment->id, 'user_id' => $payment->user_id],
            ], $sessionChanges)],
        ], $eventChanges);
    }

    private function signature(string $payload, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_local_signature_test');
    }

    private function sendEvent(array $event): TestResponse
    {
        $payload = json_encode($event);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->signature($payload), 'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
