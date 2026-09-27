<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;

/**
 * プラン詳細表示用のデータを取得するユースケース。
 */
final class ShowAction
{
    public function __invoke(Plan $plan): Plan
    {
        return $plan->load([
            'createdBy',
            'updatedBy',
            'users',
        ]);
    }
}
