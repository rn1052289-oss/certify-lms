<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * プランを削除するユースケース。
 * 下書き状態かつ User / UserPlanLog から参照されていない場合のみ削除できる。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException
     */
    public function __invoke(Plan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($lockedPlan->status !== PlanStatus::Draft) {
                throw new PlanNotDeletableException;
            }

            if ($lockedPlan->users()->withTrashed()->exists()) {
                throw new PlanNotDeletableException;
            }

            if ($lockedPlan->userPlanLogs()->exists()) {
                throw new PlanNotDeletableException;
            }

            $lockedPlan->delete();
        });
    }
}
