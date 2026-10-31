<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 開始前の面談をキャンセルし、面談回数を返却するユースケース。
 *
 * 面談予約をキャンセルするユースケース(受講生・コーチの双方から呼ばれる)。
 *
 * 処理フロー(1 〜 3 は DB::transaction() 内):
 * 1. reserved かつ開始前の面談のみ canceled に更新する
 * 2. 消費した面談回数を受講生へ返却する(RefundQuotaAction)
 * 3. コミット後、相手側(受講生が取り消したらコーチ、コーチなら受講生)へキャンセル通知を送る
 * 4. Google Calendar の予定を削除する。失敗してもキャンセルは維持し、警告文を返す
 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $calendar,
    ) {}

    /**
     * @return string|null Google Calendar との同期に失敗したときの警告文
     *
     * @throws MeetingStatusTransitionException reserved 以外の面談
     * @throws MeetingAlreadyStartedException 開始時刻を過ぎた面談
     */
    public function __invoke(Meeting $meeting, User $actor): ?string
    {
        DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            $student = User::withTrashed()->findOrFail($locked->student_id);

            ($this->refundAction)($student, $locked->id);

            DB::afterCommit(function () use ($locked, $actor): void {
                $actorIsStudent = $actor->id === $locked->student_id;
                $recipientId = $actorIsStudent ? $locked->coach_id : $locked->student_id;
                $recipientRole = $actorIsStudent ? UserRole::Coach : UserRole::Student;

                try {
                    $recipient = User::query()->find($recipientId);

                    if (
                        $recipient === null
                        || $recipient->id === $actor->id
                        || $recipient->role !== $recipientRole
                        || $recipient->status !== UserStatus::InProgress
                    ) {
                        return;
                    }

                    $recipient->notify(new MeetingCanceledNotification($locked));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        });

        $this->calendar->cancel($meeting);

        return $this->calendar->warning();
    }
}
