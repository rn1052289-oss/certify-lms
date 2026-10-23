<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendarService;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\UpsertMemoAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

class CancelAndMemoActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_uses_explicit_actor_refunds_student_and_notifies_only_counterparty(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->admin()->create());
        foreach (['student', 'coach'] as $actorRole) {
            $meeting = $this->reservedMeeting();
            $actor = $actorRole === 'student' ? $meeting->student : $meeting->coach;
            $recipient = $actorRole === 'student' ? $meeting->coach : $meeting->student;
            $this->calendarMock(true);

            $warning = app(CancelAction::class)($meeting, $actor);

            $fresh = $meeting->fresh();
            $this->assertSame(MeetingStatus::Canceled, $fresh->status);
            $this->assertSame($actor->id, $fresh->canceled_by_user_id);
            $this->assertNotNull($fresh->canceled_at);
            $this->assertSame(GoogleCalendarService::WARNING, $warning);
            $this->assertSame(3, app(MeetingQuotaService::class)->remaining($meeting->student));
            $this->assertDatabaseHas('meeting_quota_transactions', [
                'related_meeting_id' => $meeting->id,
                'user_id' => $meeting->student_id,
                'type' => MeetingQuotaTransactionType::Refunded->value,
                'amount' => 1,
            ]);
            Notification::assertSentTo($recipient, MeetingCanceledNotification::class);
            Notification::assertNotSentTo($actor, MeetingCanceledNotification::class);
        }
    }

    public function test_cancel_reloads_status_and_does_not_refund_again_for_stale_input(): void
    {
        Notification::fake();
        $meeting = $this->reservedMeeting();
        $this->calendarMock(true);
        app(CancelAction::class)($meeting, $meeting->student);

        try {
            app(CancelAction::class)($meeting, $meeting->student);
            $this->fail('同じ面談が再度キャンセルされました。');
        } catch (MeetingStatusTransitionException) {
            $this->assertSame(1, MeetingQuotaTransaction::query()->where('related_meeting_id', $meeting->id)->where('type', MeetingQuotaTransactionType::Refunded)->count());
            $this->assertSame(3, app(MeetingQuotaService::class)->remaining($meeting->student));
            Notification::assertSentToTimes($meeting->coach, MeetingCanceledNotification::class, 1);
        }
    }

    public function test_cancel_at_start_time_does_not_refund_sync_or_notify(): void
    {
        $this->freezeTime();
        Notification::fake();
        $meeting = $this->reservedMeeting();
        $meeting->update(['scheduled_at' => now()]);
        $this->calendarMock(false);

        try {
            app(CancelAction::class)($meeting, $meeting->student);
            $this->fail('開始時刻の面談がキャンセルされました。');
        } catch (MeetingAlreadyStartedException) {
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
            $this->assertNull($meeting->fresh()->canceled_at);
            $this->assertSame(2, app(MeetingQuotaService::class)->remaining($meeting->student));
            Notification::assertNothingSent();
        }
    }

    public function test_refund_write_failure_rolls_back_cancellation_and_does_not_sync_or_notify(): void
    {
        Notification::fake();
        $meeting = $this->reservedMeeting();
        $this->calendarMock(false);
        MeetingQuotaTransaction::creating(function (): void {
            throw new \RuntimeException('refund write failed');
        });

        try {
            app(CancelAction::class)($meeting, $meeting->coach);
            $this->fail('返却履歴の保存失敗が無視されました。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('refund write failed', $exception->getMessage());
            $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
            $this->assertNull($meeting->fresh()->canceled_by_user_id);
            $this->assertNull($meeting->fresh()->canceled_at);
            $this->assertSame(2, app(MeetingQuotaService::class)->remaining($meeting->student));
            $this->assertDatabaseMissing('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'type' => MeetingQuotaTransactionType::Refunded->value]);
            Notification::assertNothingSent();
        }
    }

    public function test_cancellation_notification_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        Notification::fake();
        $meeting = $this->reservedMeeting();
        DB::beginTransaction();
        try {
            $this->calendarMock(true);
            app(CancelAction::class)($meeting, $meeting->student);

            Notification::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($meeting->student));
        Notification::assertNothingSent();
    }

    public function test_upsert_memo_creates_and_updates_one_record_for_reserved_and_completed_meetings(): void
    {
        foreach ([MeetingStatus::Reserved, MeetingStatus::Completed] as $status) {
            $meeting = Meeting::factory()->create(['status' => $status]);
            app(UpsertMemoAction::class)($meeting, '作成した面談記録');
            $memo = MeetingMemo::query()->where('meeting_id', $meeting->id)->firstOrFail();

            app(UpsertMemoAction::class)($meeting, '更新した面談記録');

            $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
            $this->assertSame('更新した面談記録', $memo->fresh()->body);
        }
    }

    public function test_upsert_memo_rejects_canceled_meeting_without_changing_existing_memo(): void
    {
        $meeting = Meeting::factory()->canceled()->create();
        $memo = MeetingMemo::factory()->forMeeting($meeting)->create(['body' => '既存の記録']);

        try {
            app(UpsertMemoAction::class)($meeting, '変更する記録');
            $this->fail('キャンセル済みのメモが更新されました。');
        } catch (MeetingStatusTransitionException) {
            $this->assertSame('既存の記録', $memo->fresh()->body);
            $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
        }
    }

    private function reservedMeeting(): Meeting
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forStudent($student)->forCoach($coach)->create(['scheduled_at' => now()->addDays(3)->startOfHour()]);
        $transaction = app(ConsumeQuotaAction::class)($student, $meeting->id);
        $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

        return $meeting;
    }

    private function calendarMock(bool $canceled): void
    {
        $level = DB::transactionLevel();
        $this->partialMock(GoogleCalendarService::class, function (MockInterface $mock) use ($level, $canceled): void {
            if ($canceled) {
                $mock->shouldReceive('cancel')->once()->andReturnUsing(function (Meeting $meeting) use ($level): void {
                    $this->assertSame($level, DB::transactionLevel(), 'Google 取消同期が取消トランザクション内です。');
                    $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
                    $this->assertDatabaseHas('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'type' => MeetingQuotaTransactionType::Refunded->value]);
                });
            } else {
                $mock->shouldNotReceive('cancel');
            }
            $mock->shouldReceive('warning')->zeroOrMoreTimes()->andReturn(GoogleCalendarService::WARNING);
        });
    }
}
