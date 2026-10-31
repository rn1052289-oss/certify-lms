<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Payment モデルのリレーション・Cast を検証する Unit テスト。
 * 2 リレーション (user / meetingPack) + 4 cast を網羅する。user は退会済みユーザーも返すことを確認する。
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_includes_withdrawn_student(): void
    {
        $student = User::factory()->student()->create();

        $payment = Payment::factory()->create(['user_id' => $student->id]);
        $student->delete();

        $this->assertTrue($payment->fresh()->user->is($student));
    }

    public function test_meeting_pack_relation_returns_purchased_pack(): void
    {
        $pack = MeetingPack::factory()->published()->create();

        $payment = Payment::factory()->create(['meeting_pack_id' => $pack->id]);

        $this->assertTrue($payment->meetingPack->is($pack));
    }

    public function test_casts_convert_amount_quantity_status_and_paid_at(): void
    {
        $payment = Payment::factory()->create([
            'amount' => '9000',
            'quantity' => '3',
            'status' => PaymentStatus::Succeeded,
            'paid_at' => '2026-10-20 12:00:00',
        ])->fresh();

        $this->assertSame(9000, $payment->amount);
        $this->assertSame(3, $payment->quantity);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertInstanceOf(Carbon::class, $payment->paid_at);
    }
}
