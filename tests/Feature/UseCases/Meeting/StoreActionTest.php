<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\GoogleCalendarService;
use App\Services\MeetingQuotaService;
use App\UseCases\Meeting\StoreAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_assigns_coach_consumes_quota_and_returns_calendar_warning(): void
    {
        Notification::fake();
        [$enrollment, $coach, $scheduledAt] = $this->bookingData();
        $this->calendarMock(true);

        $result = app(StoreAction::class)($enrollment, ['scheduled_at' => $scheduledAt->toIso8601String(), 'topic' => '学習計画の相談']);

        $meeting = $result['meeting'];
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame($enrollment->id, $meeting->enrollment_id);
        $this->assertSame($enrollment->user_id, $meeting->student_id);
        $this->assertSame($coach->id, $meeting->coach_id);
        $this->assertSame($coach->meeting_url, $meeting->meeting_url_snapshot);
        $this->assertSame('学習計画の相談', $meeting->topic);
        $this->assertTrue($meeting->scheduled_at->equalTo($scheduledAt));
        $this->assertSame(GoogleCalendarService::WARNING, $result['warning']);
        $this->assertSame(2, app(MeetingQuotaService::class)->remaining($enrollment->user));
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'id' => $meeting->meeting_quota_transaction_id,
            'user_id' => $enrollment->user_id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -1,
        ]);
        Notification::assertSentTo($coach, MeetingReservedNotification::class);
        Notification::assertNotSentTo($enrollment->user, MeetingReservedNotification::class);
    }

    public function test_insufficient_quota_does_not_create_meeting_sync_calendar_or_notify(): void
    {
        Notification::fake();
        [$enrollment, $coach, $scheduledAt] = $this->bookingData(0);
        $this->calendarMock(false);

        try {
            app(StoreAction::class)($enrollment, ['scheduled_at' => $scheduledAt->toIso8601String(), 'topic' => '予約できない相談']);
            $this->fail('残数0の予約が成立しました。');
        } catch (InsufficientMeetingQuotaException) {
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_quota_write_failure_rolls_back_meeting_and_does_not_sync_or_notify(): void
    {
        Notification::fake();
        [$enrollment, $coach, $scheduledAt] = $this->bookingData();
        $this->calendarMock(false);
        MeetingQuotaTransaction::creating(function (): void {
            throw new \RuntimeException('quota write failed');
        });

        try {
            app(StoreAction::class)($enrollment, ['scheduled_at' => $scheduledAt->toIso8601String(), 'topic' => '保存失敗の相談']);
            $this->fail('残数履歴の保存失敗が無視されました。');
        } catch (\RuntimeException $exception) {
            $this->assertSame('quota write failed', $exception->getMessage());
            $this->assertDatabaseCount('meetings', 0);
            $this->assertDatabaseCount('meeting_quota_transactions', 0);
            $this->assertSame(3, app(MeetingQuotaService::class)->remaining($enrollment->user));
            Notification::assertNothingSent();
        }
    }

    public function test_booking_notification_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        Notification::fake();
        [$enrollment, $coach, $scheduledAt] = $this->bookingData();
        DB::beginTransaction();
        try {
            $this->calendarMock(true);
            app(StoreAction::class)($enrollment, ['scheduled_at' => $scheduledAt->toIso8601String(), 'topic' => '通知の確定待ち']);

            Notification::assertNothingSent();
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        Notification::assertNothingSent();
    }

    /**
     * @return array{Enrollment, User, Carbon}
     */
    private function bookingData(int $quota = 3): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => $quota]);
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/assigned-coach']);
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $certification->id, 'user_id' => $coach->id]);
        CoachAvailability::factory()->forCoach($coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        return [$enrollment, $coach, now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0)];
    }

    private function calendarMock(bool $reserved): void
    {
        $level = DB::transactionLevel();
        $this->partialMock(GoogleCalendarService::class, function (MockInterface $mock) use ($level, $reserved): void {
            $mock->shouldReceive('busyForCoaches')->atLeast()->once()->andReturnUsing(function () use ($level): array {
                $this->assertSame($level, DB::transactionLevel(), 'Google busy 取得が予約トランザクション内です。');

                return [];
            });
            if ($reserved) {
                $mock->shouldReceive('reserve')->once()->andReturnUsing(function (Meeting $meeting) use ($level): void {
                    $this->assertSame($level, DB::transactionLevel(), 'Google 予約同期が予約トランザクション内です。');
                    $this->assertNotNull($meeting->meeting_quota_transaction_id);
                    $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
                });
            } else {
                $mock->shouldNotReceive('reserve');
            }
            $mock->shouldReceive('warning')->zeroOrMoreTimes()->andReturn(GoogleCalendarService::WARNING);
        });
    }
}
