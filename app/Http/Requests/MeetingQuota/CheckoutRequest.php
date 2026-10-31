<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 受講生の追加面談購入(Stripe Checkout 開始)リクエスト。
 *
 * 受講中の受講生のみ。公開中(published)の面談パックを 1 つ受け取る。
 *
 * @see MeetingQuotaCheckoutController::create()
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Student
            && $this->user()?->status === UserStatus::InProgress;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'meeting_pack_id' => [
                'required',
                'ulid',
                Rule::exists('meeting_packs', 'id')->where('status', MeetingPackStatus::Published->value),
            ],
        ];
    }
}
