<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランをアーカイブするユースケース。
 */
final class ArchiveAction
{
    /**
     * @throws PlanInvalidTransitionException
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        return DB::transaction(function () use ($plan, $admin) {
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($lockedPlan->status !== PlanStatus::Published) {
                throw PlanInvalidTransitionException::forArchive();
            }

            $lockedPlan->update([
                'status' => PlanStatus::Archived->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $lockedPlan->fresh();
        });
    }
}
