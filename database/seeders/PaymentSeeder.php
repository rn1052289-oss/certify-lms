<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\ProcessStripeWebhookAction;
use Illuminate\Database\Seeder;
use Stripe\Event;

/**
 * 固定・デモ受講生の購入履歴を、外部通信せずに状態別で用意する。再実行で二重付与しない。
 */
class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $pack = MeetingPack::query()->published()->ordered()->first();
        if ($pack === null) {
            $this->command?->warn('PaymentSeeder: 先に UserSeeder と MeetingPackSeeder を実行してください。');

            return;
        }

        $students = User::query()
            ->where('role', UserRole::Student)
            ->where('status', UserStatus::InProgress)
            // 既存の残数 0 専用アカウントは、その検証条件を維持する。
            ->where('email', '!=', 'student-noquota@certify-lms.test')
            ->get();
        foreach ($students as $student) {
            foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Succeeded] as $status) {
                $sessionId = 'cs_test_seed_'.$student->id.'_'.$status->value;
                $payment = Payment::firstOrCreate(['stripe_checkout_session_id' => $sessionId], [
                    'user_id' => $student->id,
                    'meeting_pack_id' => $pack->id,
                    'meeting_pack_name' => $pack->name,
                    'amount' => $pack->price,
                    'quantity' => $pack->meeting_count,
                    'currency' => 'jpy',
                    'status' => $status === PaymentStatus::Succeeded ? PaymentStatus::Pending : $status,
                ]);

                if ($status !== PaymentStatus::Succeeded) {
                    continue;
                }

                app(ProcessStripeWebhookAction::class)(Event::constructFrom([
                    'object' => 'event',
                    'type' => 'checkout.session.completed',
                    'data' => ['object' => [
                        'object' => 'checkout.session',
                        'id' => $sessionId,
                        'mode' => 'payment',
                        'status' => 'complete',
                        'payment_status' => 'paid',
                        'payment_intent' => 'pi_seed_'.$payment->id,
                        'client_reference_id' => $payment->id,
                        'amount_total' => $payment->amount,
                        'currency' => $payment->currency,
                        'metadata' => ['payment_id' => $payment->id, 'user_id' => $student->id],
                    ]],
                ]));
            }
        }
    }
}
