<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MockExamSession;

use App\Enums\MockExamSessionStatus;
use App\Enums\TermType;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TermJudgementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_start_and_cancellation_keep_the_practice_term(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->published()->create();
        $sessions = MockExamSession::factory()->for($enrollment)->for($exam)->count(2)
            ->create(['status' => MockExamSessionStatus::NotStarted->value]);
        $barrier = sys_get_temp_dir().'/mock-term-'.Str::uuid();
        File::makeDirectory($barrier);
        $processes = [];

        try {
            foreach (['start', 'cancel'] as $index => $operation) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/change_mock_session.php'),
                    $operation, $sessions[$index]->id, $barrier,
                ], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'MAIL_MAILER' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'CACHE_DRIVER' => 'array',
                ]);
                $process->setTimeout(20);
                $processes[] = $process;
            }

            $processes[0]->start();
            $this->waitForFile($barrier.'/start-read');
            $processes[1]->start();
            $this->waitForFile($barrier.'/cancel-invoked');

            // 修正前は取消の古い判定まで進み、修正後は受講登録のロックで待つ。
            $deadline = microtime(true) + 1;
            while (! file_exists($barrier.'/cancel-read') && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($barrier.'/release-start');

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            }

            $this->assertSame(MockExamSessionStatus::InProgress, $sessions[0]->fresh()->status);
            $this->assertSame(MockExamSessionStatus::Canceled, $sessions[1]->fresh()->status);
            $this->assertSame(TermType::MockPractice, $enrollment->fresh()->current_term);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            File::deleteDirectory($barrier);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                $this->fail('並行処理の同期がタイムアウトしました。');
            }
            usleep(10000);
        }
    }
}
