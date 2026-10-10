<?php

declare(strict_types=1);

namespace App\Http\Requests\UserAvatar;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ログイン中ユーザー本人のアバター画像アップロードを検証する。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'アイコン画像',
        ];
    }
}
