<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;

/**
 * 個人学習目標を新規作成する Action。
 */
final class StoreAction
{
    /**
     * @param array{
     *     title: string,
     *     description?: ?string,
     *     target_date?: ?string
     * } $validated
     */
    public function __invoke(Enrollment $enrollment, array $validated): EnrollmentGoal
    {
        return $enrollment->goals()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
            'achieved_at' => null,
        ]);
    }
}
