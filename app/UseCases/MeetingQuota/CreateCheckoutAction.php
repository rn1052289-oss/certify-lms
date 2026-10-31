<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

/**
 * 受講生の追加面談購入を開始し、Stripe Checkout の URL を返すユースケース。
 *
 * 処理フロー:
 * 1. 受講生行と面談パックをロックし、受講中の受講生 × 公開中のパックであることを確認して、金額・回数を控えた Payment(pending)を作る
 * 2. DB ロックの外で Checkout Session を作成する(通信失敗は 503。控えは残し、遅れて届く通知と照合できるようにする)
 * 3. Payment に Session ID を保存する
 */
final class CreateCheckoutAction
{
    public function __construct(private readonly StripeCheckoutService $checkout) {}

    /**
     * @return string Stripe Checkout の URL
     */
    public function __invoke(User $student, string $packId): string
    {
        abort_unless(filled(config('services.stripe.secret')), 503, '決済サービスを利用できません。');

        $payment = DB::transaction(function () use ($student, $packId) {
            $lockedStudent = User::query()->lockForUpdate()->findOrFail($student->id);
            abort_unless($lockedStudent->role === UserRole::Student && $lockedStudent->status === UserStatus::InProgress, 403);

            $pack = MeetingPack::query()->lockForUpdate()->findOrFail($packId);
            abort_unless($pack->status === MeetingPackStatus::Published, 404);
            abort_unless($pack->price > 0 && $pack->meeting_count > 0, 422);

            return Payment::create([
                'user_id' => $lockedStudent->id,
                'meeting_pack_id' => $pack->id,
                'meeting_pack_name' => $pack->name,
                'amount' => $pack->price,
                'quantity' => $pack->meeting_count,
                'currency' => 'jpy',
                'status' => PaymentStatus::Pending,
            ]);
        });

        // 外部通信は DB ロックの外で行う。通信失敗でも控えを残し、遅れて届く通知を照合できるようにする。
        try {
            $session = $this->checkout->create($payment);
        } catch (ApiErrorException) {
            abort(503, '決済画面を開けませんでした。時間をおいてお試しください。');
        }

        abort_unless(is_string($session->id) && str_starts_with($session->id, 'cs_')
            && is_string($session->url) && str_starts_with($session->url, 'https://checkout.stripe.com/'), 503);

        DB::transaction(function () use ($payment, $session) {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_if($lockedPayment->stripe_checkout_session_id !== null && $lockedPayment->stripe_checkout_session_id !== $session->id, 409);
            $lockedPayment->update(['stripe_checkout_session_id' => $session->id]);
        });

        return $session->url;
    }
}
