<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックの基本情報を更新するユースケース。
 * `status` は状態遷移用Actionで変更する。
 */
final class UpdateAction
{
    /**
     * @param array{
     *     name: string,
     *     description?: ?string,
     *     meeting_count: int,
     *     price: int,
     *     stripe_price_id?: ?string,
     *     sort_order?: ?int
     * } $validated
     */
    public function __invoke(MeetingPack $plan, User $admin, array $validated): MeetingPack
    {
        return DB::transaction(function () use ($plan, $admin, $validated) {
            $plan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'meeting_count' => $validated['meeting_count'],
                'price' => $validated['price'],
                'stripe_price_id' => $validated['stripe_price_id'] ?? null,
                'sort_order' => $validated['sort_order'] ?? 0,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
