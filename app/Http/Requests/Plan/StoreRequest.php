<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * プラン新規作成の入力値を検証する FormRequest。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'default_meeting_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'プラン名',
            'description' => '説明',
            'duration_days' => '受講期間',
            'default_meeting_quota' => '初期面談回数',
            'sort_order' => '表示順',
        ];
    }
}
