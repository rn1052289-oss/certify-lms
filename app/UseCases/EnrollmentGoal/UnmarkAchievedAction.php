<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人学習目標を未達成に戻す Action。
 */
final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        $goal->update([
            'achieved_at' => null,
        ]);

        return $goal->refresh();
    }
}
