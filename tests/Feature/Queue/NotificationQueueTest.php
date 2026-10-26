<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\InvitationStatus;
use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Announcement;
use App\Models\ChatMessage;
use App\Models\Invitation;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\NotificationDelivery;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use App\UseCases\Announcement\StoreAction;
use App\UseCases\Auth\IssueInvitationAction;
use App\UseCases\Invitation\ResendAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Process\Process;
use Tests\Support\Mail\FailingTransport;
use Tests\TestCase;

/** 実際のdatabase queueとworkerで、受付・送信・再試行・結果記録を検証する。 */
class NotificationQueueTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_existing_notification_types_keep_both_channels_and_wait_for_commit(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHours(12)]);
        $recipient = $meeting->student;
        $notifications = [
            new ChatMessageReceivedNotification(ChatMessage::factory()->create()),
            new QaReplyReceivedNotification(QaReply::factory()->create()),
            new MeetingReservedNotification($meeting),
            new MeetingCanceledNotification($meeting),
            new MeetingReminderNotification($meeting, 'eve'),
        ];

        DB::beginTransaction();
        foreach ($notifications as $notification) {
            $this->assertSame(['database', 'mail'], $notification->via($recipient));
            $recipient->notify($notification);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 10);
        $this->assertSame(0, $this->mailCount());
        $this->work();
        $this->assertDatabaseCount('notifications', 5);
        $this->assertSame(4, $this->mailCount());
        $this->assertSame(1, Mail::mailer('meeting_reminders')->getSymfonyTransport()->messages()->count());
        $this->assertSame(10, NotificationDelivery::query()->whereNotNull('sent_at')->count());
    }

    public function test_rollback_discards_notification_invitation_and_announcement_jobs(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $message = ChatMessage::factory()->create();
        DB::beginTransaction();
        $student->notify(new ChatMessageReceivedNotification($message));
        app(IssueInvitationAction::class)('rollback@example.test', UserRole::Coach, null, $admin);
        app(StoreAction::class)($admin, $this->payload());
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('invitations', 0);
        $this->assertSame(0, $this->mailCount());
    }

    public function test_announcement_accepts_without_delivery_then_worker_updates_actual_results(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        User::factory()->student()->inProgress()->count(2)->create();
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success', '配信を受け付けました');
        $announcement = Announcement::query()->sole();
        $this->assertSame(0, $announcement->dispatched_count);
        $this->assertSame(0, $announcement->failed_count);
        $this->assertNull($announcement->dispatched_at);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $this->mailCount());
        // worker待機中に登録された受講生へ対象が広がらない。
        $lateStudent = User::factory()->student()->inProgress()->create();
        $this->travel(1)->minutes();
        $this->work();
        $this->assertSame(2, $announcement->fresh()->dispatched_count);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, $this->mailCount());
        $this->assertSame(0, $lateStudent->notifications()->count());
    }

    public function test_large_announcement_queues_one_fanout_job_instead_of_sending_in_http(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->count(105)->create();
        app(StoreAction::class)($admin, $this->payload());

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('announcement_recipients', 105);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $this->mailCount());
        $this->work(once: true);
        $this->assertDatabaseCount('jobs', 210);
    }

    public function test_successful_channels_and_announcement_counts_are_not_repeated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        $fanout = (array) DB::table('jobs')->sole();
        unset($fanout['id']);
        $this->work();
        $this->assertSame(1, $this->mailCount());
        // fanoutと各通知が再投入されても、固定の通知IDと成功記録で重複させない。
        DB::table('jobs')->insert($fanout);
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
    }

    public function test_parallel_workers_do_not_duplicate_notifications_or_announcement_counts(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->count(3)->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        $fanout = (array) DB::table('jobs')->sole();
        unset($fanout['id']);
        DB::table('jobs')->insert($fanout);
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $workers[$i] = new Process([
                    PHP_BINARY, 'artisan', 'queue:work', 'database', '--queue=notifications',
                    '--stop-when-empty', '--sleep=0', '--max-time=15',
                ], base_path(), [
                    'APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array',
                ]);
                $workers[$i]->setTimeout(20)->start();
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
        $this->assertDatabaseCount('notifications', 3);
        $this->assertSame(3, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
        $this->assertSame(6, NotificationDelivery::query()->whereNotNull('sent_at')->count());
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_transient_failure_backoff_final_failure_and_manual_retry_are_distinct(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        $this->work(once: true); // 対象者へチャネル別ジョブを投入。
        $this->work(once: true); // databaseチャネルは成功。
        $this->useFailingMail();
        $this->work(once: true); // mailの1回目だけが失敗し、30秒後へrelease。
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertNull($announcement->fresh()->dispatched_at);
        $job = DB::table('jobs')->sole();
        $this->assertSame(1, $job->attempts);
        $this->assertGreaterThanOrEqual(now()->timestamp + 29, $job->available_at);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->travel(31)->seconds();
        $this->work(once: true); // 2回目の失敗は120秒後へrelease。
        $this->assertGreaterThanOrEqual(now()->timestamp + 119, DB::table('jobs')->sole()->available_at);
        $this->travel(121)->seconds();
        $this->work(once: true); // 3回目で最終失敗。
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(1, $announcement->fresh()->failed_count);
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
        $uuid = DB::table('failed_jobs')->sole()->uuid;
        config(['mail.default' => 'array']);
        $this->artisan('queue:retry '.$uuid)->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertSame([], $announcement->fresh()->failed_user_ids);
    }

    public function test_reminder_canceled_while_queued_does_not_mark_channels_as_sent(): void
    {
        $meeting = Meeting::factory()->create(['scheduled_at' => now()->addHours(12)]);
        $this->artisan('notifications:send-meeting-reminders --window=eve')
            ->expectsOutput('面談リマインダー（eve）：受付 4 チャネル、投入失敗 0 件。')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 4);
        $this->assertSame(0, MeetingReminderDelivery::query()->whereNotNull('database_sent_at')->count());
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 4);
        $meeting->update(['status' => MeetingStatus::Canceled]);
        $this->work();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $this->mailCount());
        $this->assertSame(0, MeetingReminderDelivery::query()->whereNotNull('mail_sent_at')->count());
    }

    public function test_reminder_can_be_accepted_again_after_queue_insertion_failure(): void
    {
        Meeting::factory()->create(['scheduled_at' => now()->addHours(12)]);
        $failOnce = true;
        DB::listen(function ($query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query->sql), 'insert into `jobs`')) {
                $failOnce = false;
                throw new \RuntimeException('Queue insertion interrupted');
            }
        });
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertFailed();
        $this->artisan('notifications:send-meeting-reminders --window=eve')->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, Mail::mailer('meeting_reminders')->getSymfonyTransport()->messages()->count());
        $this->assertSame(2, MeetingReminderDelivery::query()->whereNotNull('database_sent_at')->whereNotNull('mail_sent_at')->count());
    }

    public function test_invitation_and_reinvitation_use_queue_and_preserve_current_signed_link(): void
    {
        $admin = User::factory()->admin()->create();
        $invitation = app(IssueInvitationAction::class)('invitee@example.test', UserRole::Coach, null, $admin);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $this->mailCount());
        $current = app(ResendAction::class)($invitation->user, $admin);
        $this->assertSame(InvitationStatus::Revoked, $invitation->fresh()->status);
        $this->assertDatabaseCount('jobs', 2);
        $this->work();
        $this->assertSame(1, $this->mailCount());
        $html = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
        $url = app(InvitationMail::class, ['invitation' => $current])->content()->with['url'];
        $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $html);
    }

    public function test_successful_invitation_job_replay_does_not_send_a_second_mail(): void
    {
        $invitation = Invitation::factory()->pending()->create();
        Mail::send(new InvitationMail($invitation));
        $job = (array) DB::table('jobs')->sole();
        unset($job['id']);
        $this->work();
        DB::table('jobs')->insert($job);
        $this->work();
        $this->assertSame(1, $this->mailCount());
    }

    public function test_expired_invitation_is_not_sent_when_worker_starts(): void
    {
        $invitation = Invitation::factory()->pending()->create(['expires_at' => now()->addMinute()]);
        Mail::send(new InvitationMail($invitation));
        $this->travel(2)->minutes();
        $this->work();
        $this->assertSame(0, $this->mailCount());
    }

    public function test_password_reset_is_encrypted_queued_and_sent_by_worker(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->post('/forgot-password', ['email' => $student->email])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $this->mailCount());
        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        $this->assertStringNotContainsString($student->email, $payload['data']['command']);
        $this->assertStringNotContainsString('ResetPasswordNotification', $payload['data']['command']);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame(45, $payload['timeout']);
        $this->assertSame('30,120', $payload['backoff']);
        $this->assertGreaterThan($payload['timeout'], config('queue.connections.database.retry_after'));
        $this->work();
        $this->assertSame(1, $this->mailCount());
    }

    public function test_password_reset_that_expires_in_queue_is_not_sent(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->post('/forgot-password', ['email' => $student->email])->assertRedirect();
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->work();
        $this->assertSame(0, $this->mailCount());
    }

    private function work(bool $once = false): void
    {
        $this->artisan('queue:work database --queue=notifications --memory=512 --sleep=0 '.($once ? '--once' : '--stop-when-empty'))
            ->assertSuccessful();
    }

    private function useFailingMail(): void
    {
        Mail::extend('queue_failing', fn () => new FailingTransport);
        config(['mail.default' => 'queue_failing', 'mail.mailers.queue_failing' => ['transport' => 'queue_failing']]);
    }

    private function mailCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function payload(): array
    {
        return ['title' => 'キュー配信の検証', 'body' => '本文を維持します。', 'target_type' => 'all_students'];
    }
}
