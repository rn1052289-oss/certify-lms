<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\CertificatePdfService;
use App\Services\EnrollmentStatsService;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Dashboard\FetchAdminDashboardAction;
use App\UseCases\Enrollment\DestroyAction;
use App\UseCases\Enrollment\FailAction;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use App\UseCases\Enrollment\ResumeAction;
use App\UseCases\Enrollment\StoreAction;
use App\UseCases\User\WithdrawAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 管理者ダッシュボード集計のキャッシュ挙動を検証する Feature テスト。
 * 全体 KPI と資格別修了率の 2 キーそれぞれについて、連続表示で集計が保存済みキャッシュから
 * 再利用されること(重い集計を再実行しない)と、受講状態の遷移(EnrollmentStatusChangeService)で
 * 両キーが無効化され最新値に更新されること(片方の forget 漏れも検出する)を網羅する。
 */
class AdminDashboardCacheTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_kpi_is_served_from_cache_on_second_fetch(): void
    {
        // Arrange: admin + 受講中 2 件、キャッシュは空の状態から始める
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();

        // Act: 1 回目で集計してキャッシュ → 無効化経路を通さず DB を直接増やす → クエリ計測しつつ 2 回目
        $first = app(FetchAdminDashboardAction::class)($admin);
        Enrollment::factory()->for($cert)->learning()->count(3)->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $second = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 2 回目はキャッシュヒットで重い集計クエリが 1 本も走らず、値も 1 回目のまま(直接 INSERT は反映されない)
        $this->assertSame(2, $first->kpi['learning_count']);
        $this->assertSame(
            2,
            $second->kpi['learning_count'],
            'TTL 内で無効化イベントが無ければ集計はキャッシュから返るはず(直接 INSERT は反映されない)',
        );
        $this->assertSame(
            0,
            $queryCount,
            'キャッシュヒット時は重い集計クエリが 1 本も発行されないはず',
        );
        $this->assertTrue(
            Cache::has(config('dashboard.admin_kpi_cache_key')),
            '管理者 KPI 集計がキャッシュキーに保存されているはず',
        );
    }

    public function test_completion_rate_is_served_from_cache_on_second_fetch(): void
    {
        // Arrange: admin + 受講中 2 件(修了率 0 %)、キャッシュは空の状態から始める
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();

        // Act: 1 回目で集計してキャッシュ → 無効化経路を通さず合格者を直接増やす → クエリ計測しつつ 2 回目
        $first = app(FetchAdminDashboardAction::class)($admin);
        Enrollment::factory()->for($cert)->passed()->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        $second = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 2 回目はキャッシュヒットで集計クエリが走らず、修了率も 1 回目のまま(直接 INSERT は反映されない)
        $firstRate = $first->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];
        $secondRate = $second->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];

        $this->assertSame(0.0, $firstRate);
        $this->assertSame(
            0.0,
            $secondRate,
            'TTL 内で無効化イベントが無ければ修了率はキャッシュから返るはず(直接 INSERT は反映されない)',
        );
        $this->assertSame(
            0,
            $queryCount,
            'キャッシュヒット時は重い集計クエリが 1 本も発行されないはず',
        );
        $this->assertTrue(
            Cache::has(config('dashboard.admin_completion_rate_cache_key')),
            '資格別修了率の集計がキャッシュキーに保存されているはず',
        );
    }

    public function test_admin_kpi_cache_is_invalidated_on_enrollment_status_change(): void
    {
        // Arrange: admin + 受講中 2 件を 1 度集計してキャッシュさせる
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $enrollments = Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: 1 件を合格に遷移(状態遷移チョークポイント経由でキャッシュが無効化される)
        $target = $enrollments->first();
        $target->update(['status' => EnrollmentStatus::Passed]);
        app(EnrollmentStatusChangeService::class)->recordStatusChange(
            $target,
            EnrollmentStatus::Learning,
            EnrollmentStatus::Passed,
            $admin,
        );
        $after = app(FetchAdminDashboardAction::class)($admin);

        // Assert: KPI キャッシュが無効化され、最新の集計(learning 1 / passed 1)が返る
        $this->assertSame(
            1,
            $after->kpi['learning_count'],
            '状態遷移後は KPI キャッシュが無効化され、最新の受講中件数が返るはず',
        );
        $this->assertSame(1, $after->kpi['passed_count']);
    }

    public function test_completion_rate_cache_is_invalidated_on_enrollment_status_change(): void
    {
        // Arrange: admin + 受講中 2 件(修了率 0 %)を 1 度集計してキャッシュさせる
        $admin = User::factory()->admin()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $enrollments = Enrollment::factory()->for($cert)->learning()->count(2)->create();
        Cache::flush();
        app(FetchAdminDashboardAction::class)($admin);

        // Act: 1 件を合格に遷移(状態遷移チョークポイント経由でキャッシュが無効化される)
        $target = $enrollments->first();
        $target->update(['status' => EnrollmentStatus::Passed]);
        app(EnrollmentStatusChangeService::class)->recordStatusChange(
            $target,
            EnrollmentStatus::Learning,
            EnrollmentStatus::Passed,
            $admin,
        );
        $after = app(FetchAdminDashboardAction::class)($admin);

        // Assert: 修了率キャッシュも無効化され、最新の修了率(合格 1 / 全 2 = 0.5)が返る
        $afterRate = $after->completionRateByCertification->firstWhere('certification_id', $cert->id)['completion_rate'];
        $this->assertSame(
            0.5,
            $afterRate,
            '状態遷移後は修了率キャッシュも無効化され、最新の修了率(1/2)が返るはず',
        );
    }

    public function test_configured_ttl_refreshes_both_aggregates_and_non_status_changes(): void
    {
        config(['dashboard.admin_cache_ttl' => 10]);
        $cert = Certification::factory()->published()->create(['name' => 'Before']);
        Enrollment::factory()->for($cert)->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $first = $stats->adminKpi();
        $stats->completionRateByCertification();
        $cert->update(['name' => 'After']);
        Enrollment::factory()->for($cert)->passed()->create();

        $this->travel(9)->seconds();
        $this->assertSame($first, $stats->adminKpi());
        $this->assertSame(0.0, $stats->completionRateByCertification()->first()['completion_rate']);
        $this->travel(2)->seconds();
        $this->assertSame(2, $stats->adminKpi()['total']);
        $this->assertSame('After', $stats->adminKpi()['by_certification'][0]['certification_name']);
        $this->assertSame(0.5, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_zero_ttl_does_not_persist_aggregates(): void
    {
        config(['dashboard.admin_cache_ttl' => 0]);
        $cert = Certification::factory()->published()->create();
        $stats = app(EnrollmentStatsService::class);
        $this->assertSame(0, $stats->adminKpi()['total']);
        $this->assertEmpty($stats->completionRateByCertification());
        Enrollment::factory()->for($cert)->passed()->create();
        $this->assertSame(1, $stats->adminKpi()['total']);
        $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_registration_failure_resume_and_removal_invalidate_both_aggregates(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();

        $enrollment = app(StoreAction::class)($student, ['certification_id' => $cert->id]);
        $this->assertSame(1, $stats->adminKpi()['learning_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['learning']);
        app(FailAction::class)($enrollment, $admin, null);
        $this->assertSame(1, $stats->adminKpi()['failed_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['failed']);
        app(ResumeAction::class)($enrollment, $student);
        $this->assertSame(1, $stats->adminKpi()['learning_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['learning']);
        app(DestroyAction::class)($enrollment);
        $this->assertSame(0, $stats->adminKpi()['total']);
        $this->assertEmpty($stats->completionRateByCertification());
    }

    public function test_expiration_and_withdrawal_invalidate_both_aggregates_without_changing_count_rules(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $enrollment = Enrollment::factory()->learning()->create(['exam_date' => now()->subDay()]);
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();
        $this->artisan('enrollments:fail-expired')->assertSuccessful();
        $this->assertSame(1, $stats->adminKpi()['failed_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['failed']);

        app(WithdrawAction::class)($enrollment->user, $admin);
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
        // 現行の集計は User ではなく Enrollment の削除状態で判定する。
        $this->assertSame(1, $stats->adminKpi()['failed_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['failed']);
    }

    public function test_outer_rollback_keeps_cache_and_does_not_publish_uncommitted_aggregates(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $before = $stats->adminKpi();
        $rates = $stats->completionRateByCertification()->all();
        $cached = Cache::get(config('dashboard.admin_kpi_cache_key'));

        DB::beginTransaction();
        DB::transaction(function () use ($enrollment): void {
            $enrollment->update(['status' => EnrollmentStatus::Passed]);
            app(EnrollmentStatusChangeService::class)->recordStatusChange($enrollment, EnrollmentStatus::Learning, EnrollmentStatus::Passed, null);
        });
        $this->assertSame($cached, Cache::get(config('dashboard.admin_kpi_cache_key')));
        $this->assertSame(1, $stats->adminKpi()['passed_count']);
        $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
        $this->assertSame($cached, Cache::get(config('dashboard.admin_kpi_cache_key')));
        DB::rollBack();
        $this->assertSame($before, $stats->adminKpi());
        $this->assertSame($rates, $stats->completionRateByCertification()->all());
    }

    public function test_outer_commit_invalidates_only_after_commit(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();
        DB::beginTransaction();
        DB::transaction(function () use ($enrollment): void {
            $enrollment->update(['status' => EnrollmentStatus::Passed]);
            app(EnrollmentStatusChangeService::class)->recordStatusChange($enrollment, EnrollmentStatus::Learning, EnrollmentStatus::Passed, null);
        });
        $this->assertTrue(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertTrue(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
        DB::commit();
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
        $this->assertSame(1, $stats->adminKpi()['passed_count']);
        $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_invalidation_during_aggregation_rejects_late_stale_fill(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $changed = false;
        DB::listen(function ($query) use ($enrollment, &$changed): void {
            if ($changed || ! str_starts_with($query->sql, 'select status, COUNT(*)')) {
                return;
            }
            // 集計SQL完了とキャッシュ保存の間に別の状態変更が確定する順序を再現。
            $changed = true;
            $enrollment->update(['status' => EnrollmentStatus::Passed]);
            app(EnrollmentStatusChangeService::class)->recordStatusChange($enrollment, EnrollmentStatus::Learning, EnrollmentStatus::Passed, null);
        });
        $this->assertSame(1, $stats->adminKpi()['learning_count']);
        $this->assertTrue($changed);
        $this->assertSame(0, $stats->adminKpi()['learning_count']);
        $this->assertSame(1, $stats->adminKpi()['passed_count']);
        $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_disabling_cache_bypasses_existing_cached_values(): void
    {
        $cert = Certification::factory()->published()->create();
        Enrollment::factory()->for($cert)->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();
        config(['dashboard.admin_cache_ttl' => 0]);
        Enrollment::factory()->for($cert)->passed()->create();
        $this->assertSame(2, $stats->adminKpi()['total']);
        $this->assertSame(0.5, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_cache_read_failure_preserves_dashboard_aggregates(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        Enrollment::factory()->passed()->create();
        $cache = \Mockery::mock(Cache::store());
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache read unavailable'));
        Cache::swap($cache);
        $vm = app(FetchAdminDashboardAction::class)($admin);
        $this->assertNotNull($vm->kpi);
        $this->assertSame(1, $vm->kpi['passed_count']);
        $this->assertSame(1.0, $vm->completionRateByCertification->first()['completion_rate']);
    }

    public function test_cache_write_failure_preserves_dashboard_aggregates(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        Enrollment::factory()->passed()->create();
        $cache = \Mockery::mock(Cache::store());
        $cache->shouldReceive('put')->andThrow(new \RuntimeException('cache write unavailable'));
        Cache::swap($cache);
        $vm = app(FetchAdminDashboardAction::class)($admin);
        $this->assertNotNull($vm->kpi);
        $this->assertSame(1, $vm->kpi['passed_count']);
        $this->assertSame(1.0, $vm->completionRateByCertification->first()['completion_rate']);
    }

    public function test_cache_invalidation_failure_does_not_delete_committed_certificate_pdf(): void
    {
        Storage::fake('private');
        $enrollment = Enrollment::factory()->learning()->create();
        $exam = MockExam::factory()->for($enrollment->certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        $this->mock(CertificatePdfService::class)->shouldReceive('generate')->once()->andReturn('%PDF-test');
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();
        $cache = \Mockery::mock(Cache::store());
        $cache->shouldReceive('forever')->once()->andThrow(new \RuntimeException('cache invalidation unavailable'));
        Cache::swap($cache);

        $error = null;
        try {
            app(ReceiveCertificateAction::class)($enrollment);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertSame(EnrollmentStatus::Passed, $enrollment->refresh()->status);
        $this->assertDatabaseCount('certificates', 1);
        $certificate = Certificate::query()->sole();
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertNull($error, 'cache例外を確定済みの修了処理へ伝播させない');
        // 世代書込の失敗後も両集計の削除は試みる。
        $this->assertFalse(Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertFalse(Cache::has(config('dashboard.admin_completion_rate_cache_key')));
        $this->assertSame(1, $stats->adminKpi()['passed_count']);
        $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
    }

    public function test_failed_cache_deletions_still_reject_previous_generation(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();
        $cache = \Mockery::mock(Cache::store());
        $cache->shouldReceive('forget')->twice()->andThrow(new \RuntimeException('cache delete unavailable'));
        Cache::swap($cache);
        app(FailAction::class)($enrollment, $admin, null);
        $this->assertSame(1, $stats->adminKpi()['failed_count']);
        $this->assertSame(1, $stats->completionRateByCertification()->first()['failed']);
    }

    public function test_file_cache_rejects_stale_fills_from_another_process(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $barrier = sys_get_temp_dir().'/admin-stats-'.Str::uuid();
        File::makeDirectory($barrier);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $barrier.'/cache']);
        Cache::purge('file');
        $stats = app(EnrollmentStatsService::class);
        $process = null;

        try {
            foreach (['adminKpi', 'completionRateByCertification'] as $method) {
                $enrollment->update(['status' => EnrollmentStatus::Learning]);
                $stats->invalidateAfterCommit();
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/fetch_admin_stats.php'), $barrier, $method,
                ], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'CACHE_DRIVER' => 'file',
                    'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(20);
                $process->start();
                $deadline = microtime(true) + 10;
                while (! file_exists($barrier.'/ready')) {
                    if (! $process->isRunning() || microtime(true) > $deadline) {
                        $this->fail('集計プロセスの同期待ち失敗: '.$process->getErrorOutput().$process->getOutput());
                    }
                    usleep(10000);
                }

                DB::transaction(function () use ($enrollment): void {
                    $enrollment->update(['status' => EnrollmentStatus::Passed]);
                    app(EnrollmentStatusChangeService::class)->recordStatusChange($enrollment, EnrollmentStatus::Learning, EnrollmentStatus::Passed, null);
                });
                touch($barrier.'/release');
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $old = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(0, $old['passed']);
                $this->assertSame(1, $stats->adminKpi()['passed_count']);
                $this->assertSame(1.0, $stats->completionRateByCertification()->first()['completion_rate']);
                unlink($barrier.'/ready');
                unlink($barrier.'/release');
            }
        } finally {
            if ($process?->isRunning()) {
                $process->stop();
            }
            Cache::purge('file');
            File::deleteDirectory($barrier);
        }
    }
}
