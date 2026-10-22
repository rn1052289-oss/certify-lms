<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateGenerationException;
use App\Exceptions\Enrollment\CompletionNotEligibleException;
use App\Exceptions\Enrollment\EnrollmentNotLearningException;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\CertificatePdfService;
use App\UseCases\Enrollment\ReceiveCertificateAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 受講生による「修了証を受け取る」自己発火 Action のロジックを直接検証する。
 * Controller 経由の認可は別途 EnrollmentControllerTest で検証する。
 */
class ReceiveCertificateActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_successfully_issues_certificate_and_records_status_log_when_all_published_exams_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        $certificate = app(ReceiveCertificateAction::class)($enrollment);

        $this->assertSame($enrollment->id, $certificate->enrollment_id);
        $this->assertSame($student->id, $certificate->user_id);
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => EnrollmentStatus::Passed->value,
        ]);
        $this->assertNotNull($enrollment->refresh()->passed_at);
        $this->assertDatabaseHas('enrollment_status_logs', [
            'enrollment_id' => $enrollment->id,
            'from_status' => EnrollmentStatus::Learning->value,
            'to_status' => EnrollmentStatus::Passed->value,
            'changed_by_user_id' => $student->id,
            'changed_reason' => '受講生による修了証受領',
        ]);
    }

    public function test_throws_when_not_eligible_due_to_unpassed_exam(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam1 = MockExam::factory()->for($certification)->create(['is_published' => true]);
        $exam2 = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam1)->create(['pass' => true]);
        // exam2 は合格してない

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CompletionNotEligibleException が throw されるはず');
        } catch (CompletionNotEligibleException) {
            // 期待通り
        }

        // Enrollment は learning のまま、Certificate も作成されないことを確認
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertNull($enrollment->refresh()->passed_at);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_throws_when_enrollment_status_is_already_passed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();

        $this->expectException(EnrollmentNotLearningException::class);

        app(ReceiveCertificateAction::class)($enrollment);
    }

    public function test_throws_when_enrollment_status_is_failed(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->failed()->create();

        $this->expectException(EnrollmentNotLearningException::class);

        app(ReceiveCertificateAction::class)($enrollment);
    }

    public function test_no_certificate_is_created_when_published_exam_count_is_zero(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        // 公開模試 0 件 → eligibility false

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CompletionNotEligibleException が throw されるはず');
        } catch (CompletionNotEligibleException) {
            // 期待通り
        }

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
    }

    public function test_generation_failure_rolls_back_completion_and_status_log(): void
    {
        $enrollment = $this->createEligibleEnrollment();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andThrow(new \RuntimeException('PDF generation failed'));

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CertificateGenerationException が throw されるはず');
        } catch (CertificateGenerationException) {
            // 期待通り
        }

        $this->assertCompletionWasRolledBack($enrollment);
    }

    public function test_storage_false_rolls_back_completion_and_status_log(): void
    {
        $enrollment = $this->createEligibleEnrollment();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andReturn('%PDF-test');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        $disk->shouldReceive('allFiles')->andReturn([]);
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('CertificateGenerationException が throw されるはず');
        } catch (CertificateGenerationException) {
            // 期待通り
        }

        $this->assertCompletionWasRolledBack($enrollment);
    }

    public function test_insert_failure_rolls_back_completion_and_removes_pdf(): void
    {
        $enrollment = $this->createEligibleEnrollment();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andReturn('%PDF-test');
        Certificate::creating(static function (): void {
            throw new \RuntimeException('Certificate insert failed');
        });

        try {
            try {
                app(ReceiveCertificateAction::class)($enrollment);
                $this->fail('CertificateGenerationException が throw されるはず');
            } catch (CertificateGenerationException) {
                // 期待通り
            }
        } finally {
            Certificate::flushEventListeners();
        }

        $this->assertCompletionWasRolledBack($enrollment);
    }

    public function test_rechecks_current_status_before_completing_stale_learning_model(): void
    {
        $enrollment = $this->createEligibleEnrollment();
        Enrollment::query()->whereKey($enrollment->id)->update([
            'status' => EnrollmentStatus::Failed->value,
        ]);
        $this->mock(CertificatePdfService::class)->shouldNotReceive('generate');

        try {
            app(ReceiveCertificateAction::class)($enrollment);
            $this->fail('EnrollmentNotLearningException が throw されるはず');
        } catch (EnrollmentNotLearningException) {
            // 期待通り
        }

        $this->assertSame(EnrollmentStatus::Failed, $enrollment->refresh()->status);
        $this->assertNull($enrollment->passed_at);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    private function createEligibleEnrollment(): Enrollment
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        return $enrollment;
    }

    private function assertCompletionWasRolledBack(Enrollment $enrollment): void
    {
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->refresh()->status);
        $this->assertNull($enrollment->passed_at);
        $this->assertDatabaseCount('enrollment_status_logs', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
