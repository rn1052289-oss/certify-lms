<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\UseCases\Meeting\SendReminderAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(10, 0));
    }

    public function test_eve_sends_both_channels_to_both_participants_once(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 2);
        $this->assertSame(2, Mail::mailer()->getSymfonyTransport()->messages()->count());

        foreach ([$meeting->student, $meeting->coach] as $recipient) {
            $notification = $recipient->notifications()->sole();
            $this->assertSame('meeting_reminder', $notification->data['notification_type']);
            $this->assertSame('面談の前日リマインダー', $notification->data['title']);
            $this->assertSame(route('meetings.show', $meeting), $notification->data['url']);
        }
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, Mail::mailer()->getSymfonyTransport()->messages()->count());
    }

    public function test_one_hour_before_sends_distinct_reminder_after_eve(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->travel(23)->hours();
        $this->artisan('notifications:send-meeting-reminders --window=one_hour_before')->assertSuccessful();
        $this->artisan('notifications:send-meeting-reminders --window=one_hour_before')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 4);
        $this->assertSame(4, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertSame(2, $meeting->student->notifications()->count());
        $this->assertContains('面談の1時間前リマインダー', $meeting->student->notifications()->pluck('data')->pluck('title')->all());
    }

    public function test_only_reserved_future_due_meetings_are_selected(): void
    {
        foreach ([MeetingStatus::Canceled, MeetingStatus::Completed] as $status) {
            Meeting::factory()->create(['scheduled_at' => now()->addHour(), 'status' => $status]);
        }
        Meeting::factory()->create(['scheduled_at' => now()->addHour()->addSecond()]);
        Meeting::factory()->create(['scheduled_at' => now()]);
        Meeting::factory()->create(['scheduled_at' => now()->subMinute()]);
        $target = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);

        $this->artisan('notifications:send-meeting-reminders --window=one_hour_before')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame([$target->id], MeetingReminderDelivery::query()->distinct()->pluck('meeting_id')->all());
    }

    public function test_eve_does_not_send_before_twenty_four_hours(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addDay()->addSecond()]);
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
        $this->travel(1)->seconds();
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_inactive_participants_are_excluded_without_excluding_other_party(): void
    {
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
            $meeting->student->update(['status' => $status]);
        }
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 3);
        $this->assertSame(3, MeetingReminderDelivery::query()->whereNotNull('mail_sent_at')->count());
        foreach (User::query()->where('role', 'student')->get() as $student) {
            $this->assertSame(0, $student->notifications()->count());
        }
    }

    public function test_deleted_and_wrong_role_participants_are_excluded(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
        $meeting->student->delete();
        $meeting->coach->update(['role' => 'admin']);

        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('meeting_reminder_deliveries', 0);
    }

    public function test_inactive_coach_is_excluded(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $meeting->coach->update(['status' => UserStatus::Withdrawn]);

        $this->artisan('notifications:send-meeting-reminders --window=one_hour_before')->assertSuccessful();
        $this->assertSame(1, $meeting->student->notifications()->count());
        $this->assertSame(0, $meeting->coach->notifications()->count());
    }

    public function test_invalid_or_missing_window_is_rejected_without_sending(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHour()]);
        $this->artisan('notifications:send-meeting-reminders')->assertFailed();
        $this->artisan('notifications:send-meeting-reminders --window=tomorrow')->assertFailed();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failed_mail_is_retried_without_duplicating_database_notification(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
        $mailer = Mail::mailer();
        Mail::shouldReceive('mailer')->twice()->andThrow(new \RuntimeException('mail unavailable'));

        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertFailed();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, MeetingReminderDelivery::query()->whereNotNull('database_sent_at')->whereNull('mail_sent_at')->count());

        Mail::shouldReceive('mailer')->twice()->andReturn($mailer);
        $this->travel(1)->minutes();
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, MeetingReminderDelivery::query()->whereNotNull('mail_sent_at')->count());
        $this->assertSame(2, $mailer->getSymfonyTransport()->messages()->count());
        $this->assertSame(1, $meeting->student->notifications()->count());
    }

    public function test_action_rechecks_latest_meeting_status_before_sending(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addDay()]);
        Meeting::query()->whereKey($meeting->id)->update(['status' => MeetingStatus::Canceled]);

        $result = app(SendReminderAction::class)($meeting, 'eve', now());

        $this->assertSame(['sent' => 0, 'failed' => 0], $result);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_both_windows_are_scheduled_every_minute_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'notifications:send-meeting-reminders'));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
