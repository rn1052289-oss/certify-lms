<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\UseCases\MeetingQuota\ProcessStripeWebhookAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe 決済通知 (Webhook) の受信 Controller。
 *
 * webhook_secret 未設定は 503、署名を検証できない通知は 400 を返す。検証済みイベントの処理(面談回数の加算)は
 * `ProcessStripeWebhookAction` に委譲する。
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessStripeWebhookAction $action): Response
    {
        $secret = config('services.stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);

        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            abort(400, '決済通知の署名を確認できませんでした。');
        }

        $action($event);

        return response('', 200);
    }
}
