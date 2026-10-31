<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

/**
 * 署名検証済みの Checkout イベントだけを処理する。戻り先 GET は会計を変更しない。
 */
final class ProcessStripeWebhookAction
{
    /**
     * Checkout の完了・非同期決済の成否・期限切れを Payment に反映する。
     *
     * 正常完了時は購入時に控えた回数を加算し、同じ通知を再度受け取っても二重に加算しない。対象外のイベントは何もしない。
     */
    public function __invoke(Event $event): void
    {
        if (! in_array($event->type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ], true)) {
            return;
        }

        $session = $event->data->object ?? null;
        $paymentId = $session->metadata->payment_id ?? null;
        if (! is_string($paymentId) || ! is_string($session->id ?? null)) {
            return;
        }

        DB::transaction(function () use ($event, $session, $paymentId) {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            if ($payment === null) {
                return;
            }

            // Webhook が Checkout 作成の応答より先に到着する場合は、控えと metadata で照合して ID を保存する。
            abort_unless($session->object === 'checkout.session'
                && $session->mode === 'payment'
                && $session->client_reference_id === $payment->id
                && ($session->metadata->user_id ?? null) === $payment->user_id
                && $session->amount_total === $payment->amount
                && $session->currency === $payment->currency
                && ($payment->stripe_checkout_session_id === null || $payment->stripe_checkout_session_id === $session->id)
                && ($payment->stripe_payment_intent_id === null || $payment->stripe_payment_intent_id === $session->payment_intent), 400);

            if ($payment->status === PaymentStatus::Succeeded || $payment->status === PaymentStatus::Refunded) {
                return;
            }

            if (in_array($event->type, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true)) {
                $payment->update([
                    'stripe_checkout_session_id' => $session->id,
                    'status' => PaymentStatus::Failed,
                ]);

                return;
            }

            if ($session->payment_status !== 'paid' || $session->status !== 'complete') {
                return;
            }

            $intentId = $session->payment_intent;
            abort_unless(is_string($intentId) && str_starts_with($intentId, 'pi_'), 400);

            // 卒業・論理退会後も、購入開始時の控えで確定する。既存の利用認可は変更しない。
            $payment->update([
                'stripe_checkout_session_id' => $session->id,
                'stripe_payment_intent_id' => $intentId,
                'status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
            ]);

            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => $payment->paid_at,
            ]);
        });
    }
}
