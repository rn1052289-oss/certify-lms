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
        if ($plan->status !== PlanStatus::Draft) {
            throw new PlanNotDeletableException;
        }

        if ($plan->users()->exists()) {
            throw new PlanNotDeletableException;
        }

        if ($plan->userPlanLogs()->exists()) {
            throw new PlanNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
