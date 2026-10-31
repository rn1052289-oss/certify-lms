<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\CheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 受講生の追加面談購入の HTTP エントリポイント。
 *
 * 面談パック選択 / Stripe Checkout への遷移 / 購入完了画面を扱う。面談回数の加算は完了画面では行わず、
 * 署名検証済みの決済完了通知を受けた `StripeWebhookController` 側で行う。
 * 決済サービスを利用できない(503)ときは、汎用のエラーページではなく面談パック選択へ戻して理由を表示する。
 */
class MeetingQuotaCheckoutController extends Controller
{
    public function select(): View
    {
        return view('meeting-quota.checkout-select', [
            'plans' => MeetingPack::query()->published()->ordered()->get(),
        ]);
    }

    public function create(CheckoutRequest $request, CreateCheckoutAction $action): RedirectResponse
    {
        try {
            $url = $action($request->user(), $request->validated('meeting_pack_id'));
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            return redirect()->route('meeting-quota.checkout.select')->with('error', $exception->getMessage());
        }

        return redirect()->away($url);
    }

    public function success(Request $request): View
    {
        $validated = $request->validate(['session_id' => ['required', 'string', 'max:255']]);
        $payment = Payment::query()
            ->where('user_id', $request->user()->id)
            ->where('stripe_checkout_session_id', $validated['session_id'])
            ->with('meetingPack')
            ->firstOrFail();

        return view('meeting-quota.success', ['payment' => $payment]);
    }
}
