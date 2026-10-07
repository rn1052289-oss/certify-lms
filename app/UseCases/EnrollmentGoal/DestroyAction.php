<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人学習目標を物理削除する Action。
 */
final class DestroyAction
{
    public function __invoke(EnrollmentGoal $goal): void
    {
        $goal->delete();
    }
}
