<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

/**
 * 当事者共通の面談詳細の関連情報を取得するユースケース。
 *
 * 面談詳細の表示に必要な関連(受講登録・資格・当事者・キャンセル者・面談メモ)を読み込むユースケース。
 */
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
