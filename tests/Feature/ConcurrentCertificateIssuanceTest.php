<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Services\CertificatePdfService;
use App\UseCases\Certificate\IssueAction;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** 独立した2プロセスで行ロックを確認する。同じDBのテスト群と同時起動しない。 */
class ConcurrentCertificateIssuanceTest extends TestCase
{
    use DatabaseMigrations;

    #[DataProvider('issuancePaths')]
    public function test_parallel_calls_create_only_one_certificate_and_pdf(bool $receive): void
    {
        if (! function_exists('pcntl_fork') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('独立プロセスと行ロックの確認には pcntl と MySQL が必要です。');
        }

        Storage::fake('private');
        $enrollment = $receive ? Enrollment::factory()->learning()->create() : Enrollment::factory()->passed()->create();
        if ($receive) {
            $exam = MockExam::factory()->create(['certification_id' => $enrollment->certification_id, 'is_published' => true]);
            MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        }
        app()->instance(CertificatePdfService::class, new class extends CertificatePdfService
        {
            public function generate(Certificate $certificate): string
            {
                return '%PDF-test';
            }
        });
        $directory = sys_get_temp_dir().'/certificate-issuance-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('検証用プロセスを開始できません。');
                }
                if ($pid === 0) {
                    DB::purge();
                    touch($directory.'/ready-'.$i);
                    $deadline = microtime(true) + 15;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    touch($directory.'/entered-'.$i);
                    try {
                        app($receive ? ReceiveCertificateAction::class : IssueAction::class)($enrollment);
                        $status = 200;
                    } catch (\Throwable $exception) {
                        $status = $exception instanceof HttpExceptionInterface
                            ? $exception->getStatusCode() : 500;
                    }
                    file_put_contents($directory.'/result-'.$i, (string) $status);
                    DB::disconnect();
                    exit(in_array($status, [200, 409], true) ? 0 : 1);
                }
                $children[] = $pid;
            }

            $this->waitForFiles([$directory.'/ready-0', $directory.'/ready-1']);
            DB::beginTransaction();
            Enrollment::query()->whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->waitForFiles([$directory.'/entered-0', $directory.'/entered-1']);
            usleep(300000);
            DB::commit();
            $this->waitForFiles([$directory.'/result-0', $directory.'/result-1']);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            $statuses = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
            sort($statuses);
            $this->assertSame(['200', '409'], $statuses);
            $this->assertDatabaseCount('certificates', 1);
            $certificate = Certificate::where('enrollment_id', $enrollment->id)->sole();
            $this->assertSame([$certificate->pdf_path], Storage::disk('private')->allFiles());
            $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
            $this->assertDatabaseCount('enrollment_status_logs', $receive ? 1 : 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function issuancePaths(): array
    {
        return ['direct issuance' => [false], 'completion and issuance' => [true]];
    }

    private function waitForFiles(array $files): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (count(array_filter($files, 'file_exists')) === count($files)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('検証用プロセスが制限時間内に応答しませんでした。');
    }
}
