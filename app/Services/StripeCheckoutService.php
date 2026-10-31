<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * カード情報を LMS に保存せず、購入時点の控えから Stripe Checkout を作成する。
 */
class StripeCheckoutService
{
    /**
     * Payment 1 件分の Checkout Session を作成する。完了後は購入完了画面、取消時は面談パック選択画面へ戻す。
     *
     * @throws ApiErrorException
     */
    public function create(Payment $payment): Session
    {
        $stripe = new StripeClient([
            'api_key' => config('services.stripe.secret'),
            'max_network_retries' => 2,
        ]);

        return $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'client_reference_id' => $payment->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
            ],
            'line_items' => [[
                'price_data' => [
                    'currency' => $payment->currency,
                    'unit_amount' => $payment->amount,
                    'product_data' => ['name' => $payment->meeting_pack_name],
                ],
                'quantity' => 1,
            ]],
            'success_url' => route('meeting-quota.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('meeting-quota.checkout.select'),
        ], ['idempotency_key' => 'meeting-quota-'.$payment->id]);
    }
}
