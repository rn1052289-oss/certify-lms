<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MeetingReminderLockTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mail_send_does_not_block_meeting_row_on_another_connection(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHours(24)]);
        config(['database.connections.reminder_lock_check' => config('database.connections.'.config('database.default'))]);
        $checked = 0;
        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($meeting, &$checked): void {
            if (! $event->notification instanceof MeetingReminderNotification || $event->channel !== 'mail') {
                return;
            }

            $connection = DB::connection('reminder_lock_check');
            $connection->transaction(function () use ($connection, $meeting): void {
                // 別接続から待たずに取得できなければ、SMTP中も面談行がロックされている。
                $record = $connection->table('meetings')->where('id', $meeting->id)->lock('for update nowait')->first();
                $this->assertNotNull($record);
            });
            $checked++;
        });

        try {
            $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
            $this->assertSame(2, $checked);
            $this->assertSame(30, config('mail.mailers.meeting_reminders.timeout'));
            $this->assertNull(config('mail.mailers.smtp.timeout'));
        } finally {
            DB::purge('reminder_lock_check');
        }
    }
}
