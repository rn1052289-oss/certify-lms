<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを削除するユースケース。
 * 公開中の面談パックは削除できない。
 */
final class DestroyAction
{
    /**
     * @throws MeetingPackNotDeletableException
     */
    public function __invoke(MeetingPack $plan): void
    {
        if ($plan->status === MeetingPackStatus::Published) {
            throw new MeetingPackNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
