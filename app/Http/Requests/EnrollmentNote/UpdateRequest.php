<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講生メモの編集リクエスト。
 *
 * 担当コーチは自分のメモのみ、管理者はすべてのメモを編集できる。
 * メモ本文を検証する。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return $note instanceof EnrollmentNote
            && ($this->user()?->can('update', $note) ?? false);
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
