<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランを下書き状態で新規作成するユースケース。
 */
final class StoreAction
{
    /**
     * @param array{
     *     name: string,
     *     description?: ?string,
     *     duration_days: int,
     *     default_meeting_quota: int,
     *     sort_order?: ?int
     * } $validated
     */
    public function __invoke(User $admin, array $validated): Plan
    {
        return DB::transaction(fn () => Plan::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'default_meeting_quota' => $validated['default_meeting_quota'],
            'status' => PlanStatus::Draft->value,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]));
    }
}
