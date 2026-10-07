<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人学習目標を達成済みにする Action。
 */
final class MarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        $goal->update([
            'achieved_at' => now(),
        ]);

        return $goal->refresh();
    }
}
