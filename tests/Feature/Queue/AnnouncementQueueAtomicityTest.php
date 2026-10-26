<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\Announcement;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** ジョブ保存と投入済み記録が、途中の障害でも一緒に確定・取消されることを検証する。 */
class AnnouncementQueueAtomicityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    public function test_failure_after_job_insert_rolls_back_job_and_marker_then_retry_sends_once(): void
    {
        $announcement = $this->accept();
        Event::listen(JobQueued::class, function (JobQueued $event): void {
            if ($event->job instanceof SendQueuedNotifications && $event->job->channels === ['mail']) {
                // 保存直後に障害を注入する。取消されなければ、未記録の待機ジョブが残る。
                DB::table('jobs')->where('id', $event->id)->update(['available_at' => now()->addHour()->timestamp]);
                throw new \RuntimeException('Acknowledgement interrupted after job insertion');
            }
        });
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->work();
            $this->travel(121)->seconds();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('notifications', 1);
        $mail = NotificationDelivery::query()->where('channel', 'mail')->sole();
        $this->assertNull($mail->queued_at);
        $this->assertNotNull($mail->failed_at);
        $this->assertSame(1, $announcement->fresh()->failed_count);
        $this->assertSame(0, $this->mailCount());

        Event::forget(JobQueued::class);
        $this->artisan('queue:retry '.DB::table('failed_jobs')->sole()->uuid)->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
    }

    public function test_failure_after_marker_update_also_rolls_back_job_and_marker(): void
    {
        $announcement = $this->accept();
        $failOnce = true;
        DB::listen(function ($query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query->sql), 'update `notification_deliveries`')
                && str_contains($query->sql, '`queued_at`')) {
                $failOnce = false;
                throw new \RuntimeException('Commit interrupted after marker update');
            }
        });
        $this->work(once: true);
        // 残るのは再試行待ちの展開ジョブだけ。個別ジョブと投入済み記録は取り消される。
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, DB::table('jobs')->sole()->attempts);
        $this->assertSame(0, NotificationDelivery::query()->whereNotNull('queued_at')->count());
        $this->assertDatabaseCount('notifications', 0);
        $this->travel(31)->seconds();
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
    }

    public function test_successfully_queued_pending_mail_is_not_failed_or_completed_and_is_not_requeued(): void
    {
        $announcement = $this->accept();
        Event::listen(JobQueued::class, function (JobQueued $event): void {
            if ($event->job instanceof SendQueuedNotifications && $event->job->channels === ['mail']) {
                DB::table('jobs')->where('id', $event->id)->update(['available_at' => now()->addHour()->timestamp]);
            }
        });
        $this->work();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertNotNull(NotificationDelivery::query()->where('channel', 'mail')->sole()->queued_at);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertNull($announcement->fresh()->dispatched_at);
        $this->artisan('notifications:retry-announcement '.$announcement->id)->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertNull($announcement->fresh()->dispatched_at);
        $this->travel(3601)->seconds();
        $this->work();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
    }

    public function test_direct_announcement_notifications_still_wait_for_business_commit_and_discard_rollback(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $announcement = Announcement::query()->create($this->payload() + ['created_by_user_id' => $admin->id]);
        DB::beginTransaction();
        $student->notify(new AdminAnnouncementNotification($announcement));
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 2);
        DB::beginTransaction();
        $student->notify(new AdminAnnouncementNotification($announcement));
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('notification_deliveries', 2);
    }

    public function test_another_connection_cannot_see_jobs_until_the_marker_is_committed(): void
    {
        $this->accept();
        config(['database.connections.atomic_observer' => config('database.connections.'.config('database.default'))]);
        $observed = [];
        Event::listen(JobQueued::class, function (JobQueued $event) use (&$observed): void {
            if ($event->job instanceof SendQueuedNotifications) {
                $observer = DB::connection('atomic_observer');
                $observed[] = [
                    'job_visible' => $observer->table('jobs')->where('id', $event->id)->exists(),
                    'queued_at' => $observer->table('notification_deliveries')
                        ->where('notification_id', $event->job->notification->id)
                        ->where('channel', $event->job->channels[0])->value('queued_at'),
                ];
            }
        });
        try {
            $this->work(once: true);
            $this->assertSame([
                ['job_visible' => false, 'queued_at' => null],
                ['job_visible' => false, 'queued_at' => null],
            ], $observed);
            $observer = DB::connection('atomic_observer');
            $this->assertSame(2, $observer->table('jobs')->count());
            $this->assertSame(2, $observer->table('notification_deliveries')->whereNotNull('queued_at')->count());
        } finally {
            DB::purge('atomic_observer');
        }
    }

    private function accept(): Announcement
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->create();

        return app(StoreAction::class)($admin, $this->payload())['announcement'];
    }

    private function work(bool $once = false): void
    {
        $this->artisan('queue:work database --queue=notifications --sleep=0 --memory=512 '.($once ? '--once' : '--stop-when-empty'))
            ->assertSuccessful();
    }

    private function mailCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function payload(): array
    {
        return ['title' => '投入の原子性検証', 'body' => 'ジョブと記録を一緒に確定します。', 'target_type' => 'all_students'];
    }
}
