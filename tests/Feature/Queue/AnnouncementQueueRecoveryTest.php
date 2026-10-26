<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Jobs\DispatchAnnouncement;
use App\Models\Announcement;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Services\NotificationDeliveryService;
use App\UseCases\Announcement\StoreAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** 受付後の投入障害・展開の部分失敗と、送信中のロック範囲を検証する。 */
class AnnouncementQueueRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
    }

    protected function tearDown(): void
    {
        Queue::createPayloadUsing(null);
        parent::tearDown();
    }

    public function test_initial_enqueue_failure_preserves_acceptance_and_can_be_resumed_without_duplicates(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        User::factory()->student()->inProgress()->create();
        Queue::createPayloadUsing(function (): array {
            throw new \RuntimeException('Initial enqueue unavailable');
        });
        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload())
            ->assertRedirect()->assertSessionHas('success', '配信を受け付けました');
        $announcement = Announcement::query()->sole();
        $recipients = DB::table('announcement_recipients')->get();
        $this->assertCount(1, $recipients);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertNull($announcement->dispatched_at);
        $this->artisan('notifications:retry-announcement '.$announcement->id)->assertFailed();
        $this->assertDatabaseCount('jobs', 0);

        Queue::createPayloadUsing(null);
        $this->artisan('notifications:retry-announcement '.$announcement->id)->assertSuccessful();
        $this->work();
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertEquals($recipients, DB::table('announcement_recipients')->get());
        $completedAt = $announcement->fresh()->dispatched_at;
        $this->travel(1)->minutes();
        $this->artisan('notifications:retry-announcement '.$announcement->id)->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $this->mailCount());
        $this->assertEquals($completedAt, $announcement->fresh()->dispatched_at);
    }

    public function test_unknown_announcement_cannot_be_resumed(): void
    {
        $this->artisan('notifications:retry-announcement '.Str::ulid())
            ->expectsOutput('お知らせが見つかりません。')->assertFailed();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_final_fanout_failure_marks_only_unqueued_channels_and_retry_finishes_delivery(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->student()->inProgress()->count(2)->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        $accepted = 0;
        Queue::createPayloadUsing(function ($connection, $queue, $payload) use (&$accepted): array {
            if ($payload['displayName'] === AdminAnnouncementNotification::class && $accepted++ > 0) {
                throw new \RuntimeException('Fanout enqueue unavailable');
            }

            return [];
        });
        // 最初のdatabaseチャネルだけを投入し、以降は3回とも投入失敗。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->work();
            $this->travel(121)->seconds();
        }
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(2, $announcement->fresh()->failed_count);
        $this->assertSame(1, $announcement->fresh()->dispatched_count);
        $this->assertNotNull($announcement->fresh()->dispatched_at);
        $failedAt = $announcement->fresh()->dispatched_at;
        $this->assertSame(3, NotificationDelivery::query()->whereNotNull('failed_at')->count());
        $this->assertSame(0, NotificationDelivery::query()->whereNotNull('queued_at')->whereNotNull('failed_at')->count());

        Queue::createPayloadUsing(null);
        $this->artisan('queue:retry '.DB::table('failed_jobs')->sole()->uuid)->assertSuccessful();
        $this->work();
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(2, $this->mailCount());
        $this->assertSame(2, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
        $this->assertSame([], $announcement->fresh()->failed_user_ids);
        $this->assertGreaterThan($failedAt->timestamp, $announcement->fresh()->dispatched_at->timestamp);
        $this->assertSame(4, NotificationDelivery::query()->whereNotNull('sent_at')->count());
    }

    public function test_fanout_failure_does_not_mark_accepted_pending_channels_as_failed(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        $snapshot = DB::table('announcement_recipients')->sole();
        $id = app(NotificationDeliveryService::class)->plan($snapshot->notification_id, $student, 'database', ['announcement_id' => $announcement->id]);
        NotificationDelivery::query()->whereKey($id)->update(['queued_at' => now()]);
        (new DispatchAnnouncement($announcement->id))->failed(new \RuntimeException('Fanout failed'));

        $this->assertNull(NotificationDelivery::query()->findOrFail($id)->failed_at);
        $this->assertSame(1, NotificationDelivery::query()->whereNotNull('failed_at')->count());
        $this->assertSame(1, $announcement->fresh()->failed_count);
        $this->assertNull($announcement->fresh()->dispatched_at);
    }

    public function test_different_recipients_can_send_in_parallel_and_keep_accurate_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $students = User::factory()->student()->inProgress()->count(2)->create();
        $announcement = app(StoreAction::class)($admin, $this->payload())['announcement'];
        // 別プロセスで実際のmiddlewareを使い、外部送信の代わりに1秒待機する。
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$recipient = App\Models\User::findOrFail($argv[3]);
$job = (object) ['notification' => new stdClass, 'notifiables' => collect([$recipient])];
$entered = null;
(new App\Queue\Middleware\DeliverNotificationOnce((int) $argv[1], $argv[2]))->handle($job, function () use (&$entered) {
    $entered = microtime(true);
    usleep(1000000);
});
echo json_encode(['entered' => $entered]);
PHP;
        $workers = [];
        try {
            foreach ($students as $student) {
                $id = app(NotificationDeliveryService::class)->plan((string) Str::uuid(), $student, 'database', ['announcement_id' => $announcement->id]);
                $worker = new Process([PHP_BINARY, '-r', $script, (string) $id, $announcement->id, $student->id], base_path(), [
                    'APP_ENV' => 'testing', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'MAIL_MAILER' => 'array',
                ]);
                $worker->setTimeout(15)->start();
                $workers[] = $worker;
            }
            $starts = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $starts[] = json_decode($worker->getOutput(), true)['entered'];
            }
            $this->assertLessThan(0.8, abs($starts[0] - $starts[1]), '別の受講生が送信中でも開始できる');
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
        $this->assertSame(2, NotificationDelivery::query()->whereNotNull('sent_at')->count());
        $this->assertSame(2, $announcement->fresh()->dispatched_count);
        $this->assertSame(0, $announcement->fresh()->failed_count);
    }

    private function work(): void
    {
        $this->artisan('queue:work database --queue=notifications --stop-when-empty --sleep=0 --memory=512')->assertSuccessful();
    }

    private function mailCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function payload(): array
    {
        return ['title' => '復旧検証', 'body' => '受付を維持して配信を再開します。', 'target_type' => 'all_students'];
    }
}
