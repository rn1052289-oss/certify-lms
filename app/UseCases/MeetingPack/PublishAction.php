<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを公開するユースケース。
 */
final class PublishAction
{
    /**
     * @throws MeetingPackInvalidTransitionException
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        return DB::transaction(function () use ($plan, $admin) {
            $lockedPlan = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);

            if ($lockedPlan->status !== MeetingPackStatus::Draft) {
                throw MeetingPackInvalidTransitionException::forPublish();
            }

            $lockedPlan->update([
                'status' => MeetingPackStatus::Published->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $lockedPlan->fresh();
        });
    }
}
