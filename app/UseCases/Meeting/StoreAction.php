<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 残数を消費し、空き枠のコーチを自動割当して面談を予約するユースケース。
 *
 * 受講生の面談予約を作成し、担当コーチを自動割当するユースケース。
 *
 * 処理フロー:
 * 1. 枠の検証と Google Calendar の busy 取得を、DB トランザクションの外で行う
 * 2. 受講生行をロックして残面談回数を確認し、60 分間すべて対応可能かつ先約の無いコーチから割当先を決める
 * 3. Meeting を作成し、面談回数を消費する(ConsumeQuotaAction)
 * 4. 予約確定後に Google Calendar へ予定を作成する。失敗しても予約は維持し、警告文を返す
 */
final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly GoogleCalendarService $calendar,
    ) {}

    /**
     * @param array{scheduled_at: string, topic: string} $data
     *
     * @return array{meeting: Meeting, warning: ?string}
     */
    public function __invoke(Enrollment $enrollment, array $data): array
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);
        $topic = $data['topic'];
        $student = $enrollment->user;

        // Google 通信は DB トランザクションの外で行い、予約 POST でも busy を検証する。
        $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);
        $busyByCoach = $this->calendar->busyForCoaches(
            $enrollment->certification->coaches()->with('googleCredential')->get(),
            $scheduledAt,
            $scheduledAt->copy()->addHour(),
        );

        $meeting = DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic, $busyByCoach) {
            // 同一受講生の同時予約を直列化する。残数を読む前にロックし、先行予約の消費を見たうえで判定する
            $student = User::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();

            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt)
                ->reject(fn (User $coach) => $this->calendar->overlaps($busyByCoach[$coach->id] ?? [], $scheduledAt, $scheduledAt->copy()->addHour()));
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 同時刻に他受講生が先行予約した race condition: UNIQUE(coach_id, scheduled_at) で弾かれた
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            DB::afterCommit(function () use ($meeting, $student): void {
                try {
                    $coach = User::query()->find($meeting->coach_id);

                    if (
                        $coach === null
                        || $coach->id === $student->id
                        || $coach->role !== UserRole::Coach
                        || $coach->status !== UserStatus::InProgress
                    ) {
                        return;
                    }

                    $coach->notify(new MeetingReservedNotification($meeting));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $meeting->fresh();
        });

        $this->calendar->reserve($meeting);

        return ['meeting' => $meeting, 'warning' => $this->calendar->warning()];
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻から 60 分間すべてを含む有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        // 日付をまたぐ 60 分(23:00 開始)は 1 日単位の枠に収まらないため割当対象なし
        if (! $scheduledAt->copy()->addHour()->isSameDay($scheduledAt)) {
            return collect();
        }

        $time = $scheduledAt->format('H:i:s');
        $endTime = $scheduledAt->copy()->addHour()->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time, $endTime) {
                // 開始時刻だけでなく 60 分間すべてが同じ枠に収まるコーチのみを割当対象にする(B-A-05)
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>=', $endTime);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->get();
    }
}
