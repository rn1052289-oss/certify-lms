<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

/** 当事者共通の面談詳細の関連情報を取得するユースケース。 */
final class ShowAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);

        return $meeting;
    }
}
