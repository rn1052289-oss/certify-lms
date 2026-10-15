<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\User;

final class CreateAction
{
    public function __invoke(): array
    {
        return [
            'certifications' => Certification::query()->orderBy('name')->get(),
            'students' => User::query()->where('role', UserRole::Student)
                ->where('status', UserStatus::InProgress)->orderBy('name')->get(),
        ];
    }
}
