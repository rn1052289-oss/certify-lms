<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * ログイン中ユーザーのプロフィール情報を更新する。
 *
 * メールアドレス、ロール、アカウント状態は変更しない。
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:50'],
            'email' => ['sometimes', 'required', 'string', Rule::in([$user->email])],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];

        if ($user->role === UserRole::Coach) {
            $rules['meeting_url'] = ['nullable', 'string', 'url', 'max:500'];
        } else {
            $rules['meeting_url'] = ['prohibited'];
        }

        $validated = Validator::make(
            $input,
            $rules,
            [
                'name.required' => '氏名を入力してください。',
            ],
            [
                'name' => '氏名',
                'email' => 'メールアドレス',
                'bio' => '自己紹介',
                'meeting_url' => '固定面談 URL',
            ]
        )->validate();

        $attributes = [
            'name' => $validated['name'],
        ];

        if (array_key_exists('bio', $validated)) {
            $attributes['bio'] = $validated['bio'];
        }

        if ($user->role === UserRole::Coach && array_key_exists('meeting_url', $validated)) {
            $attributes['meeting_url'] = $validated['meeting_url'];
        }

        $user->forceFill($attributes)->save();
    }
}
