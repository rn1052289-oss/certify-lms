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

/** 開始前の面談をキャンセルし、面談回数を返却するユースケース。 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $calendar,
    ) {}

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
