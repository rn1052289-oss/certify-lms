<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeCheckoutService;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[Group('external-api')]
class MeetingQuotaCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private ClientInterface $originalClient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_local_only']);
        $this->originalClient = ApiRequestor::httpClient();
        // 予期しない SDK 通信もテストの失敗にする。
        ApiRequestor::setHttpClient($this->mock(ClientInterface::class));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->originalClient);
        parent::tearDown();
    }

    public function test_select_shows_only_published_packs_using_the_provided_view(): void
    {
        $visible = MeetingPack::factory()->published()->create();
        MeetingPack::factory()->draft()->create();
        MeetingPack::factory()->archived()->create();

        $this->actingAs(User::factory()->student()->create())
            ->get(route('meeting-quota.checkout.select'))
            ->assertOk()->assertViewIs('meeting-quota.checkout-select')
            ->assertViewHas('plans', fn ($plans) => $plans->modelKeys() === [$visible->id]);
    }

    public function test_checkout_sends_snapshot_values_and_idempotency_key_through_real_sdk(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->withCount(5)->withPrice(12000)->create();
        $transactionLevel = DB::transactionLevel();
        $client = $this->mock(ClientInterface::class);
        ApiRequestor::setHttpClient($client);
        $client->shouldReceive('request')->once()->andReturnUsing(function ($method, $url, $headers, $params) use ($student, $pack, $transactionLevel) {
            $payment = Payment::sole();
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertSame('post', $method);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
            $this->assertSame('payment', $params['mode']);
            $this->assertSame(['card'], $params['payment_method_types']);
            $this->assertSame($payment->id, $params['client_reference_id']);
            $this->assertSame($student->id, $params['metadata']['user_id']);
            $this->assertSame($payment->id, $params['metadata']['payment_id']);
            $this->assertSame(12000, $params['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame('jpy', $params['line_items'][0]['price_data']['currency']);
            $this->assertSame(1, $params['line_items'][0]['quantity']);
            $this->assertContains('Idempotency-Key: meeting-quota-'.$payment->id, $headers);
            $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $params['success_url']);
            // 外部通信中のマスタ変更に影響されない。
            $pack->update(['price' => 1, 'meeting_count' => 1]);

            return [json_encode(['id' => 'cs_test_checkout', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout']), 200, []];
        });

        $this->actingAs($student)->post(route('meeting-quota.checkout.create'), [
            'meeting_pack_id' => $pack->id, 'amount' => 1, 'quantity' => 100, 'user_id' => 'forged',
        ])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_checkout');

        $payment = Payment::sole();
        $this->assertSame(12000, $payment->amount);
        $this->assertSame(5, $payment->quantity);
        $this->assertSame($student->id, $payment->user_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_checkout_uses_saved_price_after_pack_changes_before_sdk_call(): void
    {
        $pack = MeetingPack::factory()->published()->withCount(5)->withPrice(12000)
            ->create(['name' => '購入時のパック']);
        $payment = Payment::factory()->create([
            'meeting_pack_id' => $pack->id,
            'meeting_pack_name' => $pack->name,
            'amount' => 12000,
            'quantity' => 5,
        ]);
        // SDK が送信内容を組み立てる前にマスタを変更する。
        $pack->update(['price' => 18000, 'meeting_count' => 1, 'name' => '変更後のパック']);

        $client = $this->mock(ClientInterface::class);
        ApiRequestor::setHttpClient($client);
        $client->shouldReceive('request')->once()->andReturnUsing(function ($method, $url, $headers, $params) {
            $priceData = $params['line_items'][0]['price_data'];
            $this->assertSame(12000, $priceData['unit_amount']);
            $this->assertSame('購入時のパック', $priceData['product_data']['name']);

            return [json_encode(['id' => 'cs_test_snapshot', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_snapshot']), 200, []];
        });

        $session = app(StripeCheckoutService::class)->create($payment);

        $this->assertSame('cs_test_snapshot', $session->id);
        $this->assertSame(18000, $pack->fresh()->price);
        $this->assertSame(12000, $payment->fresh()->amount);
        $this->assertSame(5, $payment->fresh()->quantity);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_guest_coach_admin_and_non_learning_students_cannot_start_checkout(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $this->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])->assertRedirect('/login');
        foreach ([User::factory()->coach(), User::factory()->admin(), User::factory()->student()->graduated(), User::factory()->student()->withdrawn()] as $factory) {
            $this->actingAs($factory->create())->get(route('meeting-quota.checkout.select'))->assertForbidden();
            $this->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])->assertForbidden();
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_direct_post_cannot_purchase_draft_archived_or_missing_pack(): void
    {
        $student = User::factory()->student()->create();
        foreach ([MeetingPack::factory()->draft()->create()->id, MeetingPack::factory()->archived()->create()->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            $this->actingAs($student)->postJson(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('meeting_pack_id');
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_action_rechecks_current_user_and_pack_state_after_validation(): void
    {
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->published()->create();
        $student->newQuery()->whereKey($student->id)->update(['status' => 'graduated']);
        try {
            app(CreateCheckoutAction::class)($student, $pack->id);
            $this->fail('状態が変わった受講生が購入できました。');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_checkout_failure_keeps_pending_snapshot_without_granting_quota(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $this->mock(StripeCheckoutService::class)->shouldReceive('create')->once()
            ->andThrow(new ApiConnectionException('timeout'));

        $this->actingAs(User::factory()->student()->create())
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error', '決済画面を開けませんでした。時間をおいてお試しください。');
        $this->assertDatabaseHas('payments', ['status' => 'pending', 'stripe_checkout_session_id' => null]);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_unconfigured_checkout_does_not_create_payment(): void
    {
        config(['services.stripe.secret' => null]);
        $pack = MeetingPack::factory()->published()->create();
        $this->actingAs(User::factory()->student()->create())
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error', '決済サービスを利用できません。');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unexpected_checkout_response_returns_to_select_with_message(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $this->mock(StripeCheckoutService::class)->shouldReceive('create')->once()
            ->andReturn(Session::constructFrom(['id' => 'cs_test_unexpected', 'url' => 'https://example.com/pay']));

        $this->actingAs(User::factory()->student()->create())
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error', '決済画面を開けませんでした。時間をおいてお試しください。');
        $this->assertDatabaseHas('payments', ['status' => 'pending', 'stripe_checkout_session_id' => null]);
    }

    public function test_unavailable_checkout_message_is_shown_on_select_page(): void
    {
        config(['services.stripe.secret' => null]);
        $pack = MeetingPack::factory()->published()->create();
        $this->actingAs(User::factory()->student()->create())
            ->followingRedirects()
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertOk()
            ->assertSee('決済サービスを利用できません。')
            ->assertSee($pack->name);
    }

    public function test_return_page_is_owned_read_only_and_repeated_get_never_grants_quota(): void
    {
        $payment = Payment::factory()->create();
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($payment->user)->get(route('meeting-quota.success', ['session_id' => $payment->stripe_checkout_session_id]))
                ->assertOk()->assertViewIs('meeting-quota.success')->assertViewHas('payment', fn ($p) => $p->id === $payment->id);
        }
        $this->actingAs(User::factory()->student()->create())
            ->get(route('meeting-quota.success', ['session_id' => $payment->stripe_checkout_session_id]))->assertNotFound();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_cancel_return_to_select_does_not_change_pending_payment_or_quota(): void
    {
        $payment = Payment::factory()->create();
        $this->actingAs($payment->user)->get(route('meeting-quota.checkout.select'))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }
}
