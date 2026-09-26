<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * 面談パック詳細表示用のデータを取得するユースケース。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        return $plan->load([
            'createdBy',
            'updatedBy',
        ]);
    }
}
