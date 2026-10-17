<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランを公開するユースケース。
 */
final class PublishAction
{
    /**
     * @throws PlanInvalidTransitionException
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        return DB::transaction(function () use ($plan, $admin) {
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($lockedPlan->status !== PlanStatus::Draft) {
                throw PlanInvalidTransitionException::forPublish();
            }

            $lockedPlan->update([
                'status' => PlanStatus::Published->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $lockedPlan->fresh();
        });
    }
}
