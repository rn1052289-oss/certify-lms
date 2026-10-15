<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 面談予約・キャンセル時の通知先と通知対象外条件を検証する。
 */
class MeetingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_notifies_coach_but_not_student(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '面談予約の通知を確認します。',
        ]);

        $response->assertStatus(302);
        $meeting = Meeting::query()->where('enrollment_id', $enrollment->id)->firstOrFail();

        Notification::assertSentTo($coach, MeetingReservedNotification::class, function ($notification, $channels) use ($coach, $meeting): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($coach)['url'] === route('meetings.show', $meeting);
        });
        Notification::assertNotSentTo($student, MeetingReservedNotification::class);
    }

    public function test_student_cancellation_notifies_coach_but_not_student(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $meeting = Meeting::factory()->reserved()->forEnrollment($enrollment)->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        $response->assertStatus(302);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        Notification::assertSentTo($coach, MeetingCanceledNotification::class, function ($notification, $channels) use ($coach, $meeting): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($coach)['url'] === route('meetings.show', $meeting);
        });
        Notification::assertNotSentTo($student, MeetingCanceledNotification::class);
    }

    public function test_coach_cancellation_notifies_student_but_not_coach(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $meeting = Meeting::factory()->reserved()->forEnrollment($enrollment)->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($coach)->post(route('meetings.cancel', $meeting));

        $response->assertStatus(302);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        Notification::assertSentTo($student, MeetingCanceledNotification::class, function ($notification, $channels) use ($student, $meeting): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($student)['url'] === route('meetings.show', $meeting);
        });
        Notification::assertNotSentTo($coach, MeetingCanceledNotification::class);
    }

    public function test_cancellation_does_not_notify_graduated_student(): void
    {
        Notification::fake();

        $student = User::factory()->student()->graduated()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $meeting = Meeting::factory()->reserved()->forEnrollment($enrollment)->forCoach($coach)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        $response = $this->actingAs($coach)->post(route('meetings.cancel', $meeting));

        $response->assertStatus(302);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        Notification::assertNothingSent();
    }
}
