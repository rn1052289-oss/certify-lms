<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                'exclude_unless:target_type,certification', 'required', 'ulid',
                Rule::exists('certifications', 'id'),
            ],
            'target_user_id' => [
                'exclude_unless:target_type,user', 'required', 'ulid',
                Rule::exists('users', 'id')->where('role', UserRole::Student->value)
                    ->where('status', UserStatus::InProgress->value)->whereNull('deleted_at'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'タイトル', 'body' => '本文', 'target_type' => '配信対象',
            'target_certification_id' => '対象資格', 'target_user_id' => '対象受講生',
        ];
    }
}
