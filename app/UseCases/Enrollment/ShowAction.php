<?php

declare(strict_types=1);

namespace App\UseCases\Enrollment;

use App\Models\Enrollment;

/**
 * Enrollment 詳細取得 Action。受講生 / コーチ / admin 共通(認可は Controller の Policy で済ませる前提)。
 *
 * 詳細ビューで必要な coaches / 修了証 / 最新の状態遷移ログ / 個人学習目標を eager load する。
 * 目標ごとの操作の認可(EnrollmentGoalPolicy)が親 Enrollment を参照するため、各目標へ読み込み済みの親を渡し、
 * 目標の件数分 Enrollment を読み直さないようにする。
 */
final class ShowAction
{
    public function __invoke(Enrollment $enrollment): Enrollment
    {
        $enrollment->loadMissing([
            'certification.category',
            'certification.coaches',
            'certificate',
            'latestStatusLog.changedBy',
            'goals',
        ]);
        $enrollment->goals->each->setRelation('enrollment', $enrollment);

        return $enrollment;
    }
}
