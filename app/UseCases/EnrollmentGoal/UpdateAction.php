<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人学習目標を更新する Action。
 */
final class UpdateAction
{
    /**
     * @param array{
     *     title: string,
     *     description?: ?string,
     *     target_date?: ?string
     * } $validated
     */
    public function __invoke(EnrollmentGoal $goal, array $validated): EnrollmentGoal
    {
        $goal->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'target_date' => $validated['target_date'] ?? null,
        ]);

        return $goal->refresh();
    }
}
