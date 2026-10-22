<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateGenerationException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Services\CertificatePdfService;
use App\UseCases\Certificate\IssueAction;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class TransactionFailureTest extends TestCase
{
    use DatabaseMigrations;

    public function test_outer_transaction_rejection_removes_the_pdf_after_inner_issue_succeeds(): void
    {
        Storage::fake('private');
        $enrollment = Enrollment::factory()->learning()->create();
        $exam = MockExam::factory()->create(['certification_id' => $enrollment->certification_id, 'is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);
        $this->mock(CertificatePdfService::class)->shouldReceive('generate')->once()->andReturn('%PDF-test');
        $reachedCommit = false;

        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$reachedCommit): void {
            $reachedCommit = true;
            $this->assertSame(1, Certificate::count());
            $this->assertCount(1, Storage::disk('private')->allFiles());
            // サーバー側でtransactionが中止された後にcommitが失敗する状態を再現する。
            $event->connection->getPdo()->rollBack();
            throw new RuntimeException('Transaction rejected before commit.');
        });

        try {
            try {
                app(ReceiveCertificateAction::class)($enrollment);
                $this->fail('transactionの失敗が成功扱いになりました。');
            } catch (RuntimeException $exception) {
                $this->assertSame('Transaction rejected before commit.', $exception->getMessage());
            }
        } finally {
            Event::forget(TransactionCommitting::class);
        }

        $this->assertTrue($reachedCommit);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->passed_at);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_cancelled_model_save_does_not_leave_a_file_or_claim_issuance(): void
    {
        Storage::fake('private');
        $enrollment = Enrollment::factory()->passed()->create();
        $this->mock(CertificatePdfService::class)->shouldReceive('generate')->once()->andReturn('%PDF-test');
        Certificate::saving(static fn () => false);

        try {
            try {
                app(IssueAction::class)($enrollment);
                $this->fail('レコード未保存が発行成功扱いになりました。');
            } catch (CertificateGenerationException) {
                // 期待通り
            }
        } finally {
            Certificate::flushEventListeners();
        }

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
