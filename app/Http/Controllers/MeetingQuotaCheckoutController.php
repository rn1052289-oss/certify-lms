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
        return redirect()->away($action($request->user(), $request->validated('meeting_pack_id')));
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
