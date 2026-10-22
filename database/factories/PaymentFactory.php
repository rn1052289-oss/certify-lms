<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student(),
            'meeting_pack_id' => MeetingPack::factory()->published(),
            'meeting_pack_name' => '追加面談 3 回パック',
            'amount' => 9000,
            'quantity' => 3,
            'currency' => 'jpy',
            'status' => PaymentStatus::Pending,
            'stripe_checkout_session_id' => 'cs_test_'.fake()->unique()->uuid(),
        ];
    }
}
