<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use Illuminate\Support\Facades\DB;

/**
 * 担当コーチの面談メモを作成・更新するユースケース。
 *
 * 担当コーチの面談メモを作成・更新するユースケース。reserved / completed の面談のみ(canceled には残せない)。
 */
final class UpsertMemoAction
{
    /**
     * @throws MeetingStatusTransitionException canceled の面談
     */
    public function __invoke(Meeting $meeting, string $body): void
    {
        DB::transaction(function () use ($meeting, $body) {
            if (! in_array($meeting->status, [MeetingStatus::Reserved, MeetingStatus::Completed], true)) {
                throw MeetingStatusTransitionException::forMemo();
            }

            MeetingMemo::updateOrCreate(
                ['meeting_id' => $meeting->id],
                ['body' => $body],
            );
        });
    }
}
