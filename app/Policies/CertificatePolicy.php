<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * 修了証 (Certificate) PDF ダウンロードの認可ルール。
 *
 * - admin: 全修了証
 * - student: 本人の修了証のみ
 * - coach: 現在担当している資格(certification_coach_assignments、担当解除済みを除く)の修了証のみ
 */
class CertificatePolicy
{
    public function download(User $user, Certificate $certificate): bool
    {
        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Student => $certificate->user_id === $user->id,
            UserRole::Coach => $certificate->certification?->coaches()
                ->where('users.id', $user->id)
                ->exists() ?? false,
        };
    }
}
