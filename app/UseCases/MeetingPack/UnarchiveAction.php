<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * アーカイブ済みの面談パックを下書きに戻すユースケース。
 */
final class UnarchiveAction
{
    /**
     * @throws MeetingPackInvalidTransitionException
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        return DB::transaction(function () use ($plan, $admin) {
            $lockedPlan = MeetingPack::query()->lockForUpdate()->findOrFail($plan->id);

            if ($lockedPlan->status !== MeetingPackStatus::Archived) {
                throw MeetingPackInvalidTransitionException::forUnarchive();
            }

            $lockedPlan->update([
                'status' => MeetingPackStatus::Draft->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $lockedPlan->fresh();
        });
    }
}
