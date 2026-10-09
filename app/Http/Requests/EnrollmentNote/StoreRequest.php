<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講生メモの新規作成リクエスト。
 *
 * 当該資格の担当コーチまたは管理者のみ通過し、
 * メモ本文を検証する。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            [EnrollmentNote::class, $this->route('enrollment')],
        ) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'メモ本文',
        ];
    }
}
