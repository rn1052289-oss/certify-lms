<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * お知らせ作成フォームの選択肢(資格・受講中の受講生)を取得するユースケース。
 */
final class CreateAction
{
    /**
     * @return array{certifications: Collection<int, Certification>, students: Collection<int, User>}
     */
    public function __invoke(): array
    {
        return [
            'certifications' => Certification::query()->orderBy('name')->get(),
            'students' => User::query()->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress)->orderBy('name')->get(),
        ];
    }
}
