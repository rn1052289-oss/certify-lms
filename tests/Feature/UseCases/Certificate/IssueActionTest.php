<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\CertificateGenerationException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\CertificatePdfService;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class IssueActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issues_certificate_for_passed_enrollment(): void
    {
        Storage::fake('private');
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $enrollment = Enrollment::factory()->passed()->create();

        $action = $this->app->make(IssueAction::class);
        $certificate = $action($enrollment);

        $this->assertNotNull($certificate);
        $this->assertSame($enrollment->user_id, $certificate->user_id);
        $this->assertSame($enrollment->id, $certificate->enrollment_id);
        $this->assertSame($enrollment->certification_id, $certificate->certification_id);
        $this->assertDatabaseHas('certificates', ['id' => $certificate->id]);
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));
        $this->assertTrue(now()->equalTo($certificate->issued_at));
    }

    public function test_throws_when_enrollment_not_passed(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();

        $action = $this->app->make(IssueAction::class);

        $this->expectException(EnrollmentNotPassedException::class);

        $action($enrollment);
    }

    public function test_throws_when_passed_status_but_passed_at_is_null(): void
    {
        $enrollment = Enrollment::factory()->create([
            'status' => EnrollmentStatus::Passed->value,
            'passed_at' => null,
        ]);

        $action = $this->app->make(IssueAction::class);

        $this->expectException(EnrollmentNotPassedException::class);

        $action($enrollment);
    }

    public function test_throws_already_issued_on_duplicate_enrollment_call(): void
    {
        Storage::fake('private');

        $enrollment = Enrollment::factory()->passed()->create();

        $action = $this->app->make(IssueAction::class);
        $action($enrollment);

        $this->expectException(CertificateAlreadyIssuedException::class);

        $action($enrollment);
    }

    public function test_only_one_certificate_remains_after_duplicate_call_attempt(): void
    {
        Storage::fake('private');

        $enrollment = Enrollment::factory()->passed()->create();

        $action = $this->app->make(IssueAction::class);
        $action($enrollment);

        try {
            $action($enrollment);
        } catch (CertificateAlreadyIssuedException $e) {
            // expected
        }

        $this->assertSame(1, Certificate::query()->where('enrollment_id', $enrollment->id)->count());
    }

    public function test_rechecks_current_enrollment_status_before_issuing(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        Enrollment::query()->whereKey($enrollment->id)->update([
            'status' => EnrollmentStatus::Learning->value,
            'passed_at' => null,
        ]);

        try {
            app(IssueAction::class)($enrollment);
            $this->fail('EnrollmentNotPassedException が throw されるはず');
        } catch (EnrollmentNotPassedException) {
            // 期待通り
        }

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_uses_current_passed_state_instead_of_stale_learning_model(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        $enrollment = Enrollment::factory()->learning()->create();
        $passedAt = Carbon::parse('2026-05-14 10:00:00');
        Enrollment::query()->whereKey($enrollment->id)->update([
            'status' => EnrollmentStatus::Passed->value,
            'passed_at' => $passedAt,
        ]);

        $certificate = app(IssueAction::class)($enrollment);

        $this->assertTrue(now()->equalTo($certificate->issued_at));
        Storage::disk('private')->assertExists($certificate->pdf_path);
    }

    public function test_preserves_explicit_past_issue_date_for_verification_seed_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        $enrollment = Enrollment::factory()->passed()->create();
        $issuedAt = Carbon::parse('2026-05-14 10:00:00');

        $certificate = app(IssueAction::class)($enrollment, $issuedAt);

        $this->assertTrue($issuedAt->equalTo($certificate->issued_at));
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));
    }

    public function test_generation_failure_leaves_no_certificate_or_file(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andThrow(new \RuntimeException('PDF generation failed'));

        $this->assertGenerationFailsWithoutArtifacts($enrollment);
    }

    public function test_storage_false_is_treated_as_failure(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andReturn('%PDF-test');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        $disk->shouldReceive('allFiles')->andReturn([]);
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);

        $this->assertGenerationFailsWithoutArtifacts($enrollment);
    }

    public function test_storage_exception_after_partial_write_removes_file(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $realDisk = Storage::disk('private');
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andReturn('%PDF-test');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(static function (string $path, string $contents) use ($realDisk): never {
            $realDisk->put($path, $contents);

            throw new \RuntimeException('Storage write failed');
        });
        $disk->shouldReceive('delete')->once()->andReturnUsing(fn (string $path): bool => $realDisk->delete($path));
        $disk->shouldReceive('allFiles')->andReturnUsing(fn (): array => $realDisk->allFiles());
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);

        $this->assertGenerationFailsWithoutArtifacts($enrollment);
    }

    public function test_certificate_insert_failure_removes_generated_file(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $this->mock(CertificatePdfService::class)
            ->shouldReceive('generate')->once()->andReturn('%PDF-test');
        Certificate::creating(static function (): void {
            throw new \RuntimeException('Certificate insert failed');
        });

        try {
            $this->assertGenerationFailsWithoutArtifacts($enrollment);
        } finally {
            Certificate::flushEventListeners();
        }
    }

    public function test_duplicate_preserves_existing_pdf_and_does_not_regenerate(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-original');
        $this->mock(CertificatePdfService::class)->shouldNotReceive('generate');

        try {
            app(IssueAction::class)($enrollment);
            $this->fail('CertificateAlreadyIssuedException が throw されるはず');
        } catch (CertificateAlreadyIssuedException) {
            // 期待通り
        }

        $this->assertSame('%PDF-original', Storage::disk('private')->get($certificate->pdf_path));
        $this->assertDatabaseCount('certificates', 1);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_existing_certificate_without_pdf_is_not_repaired(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();
        $this->mock(CertificatePdfService::class)->shouldNotReceive('generate');

        try {
            app(IssueAction::class)($enrollment);
            $this->fail('CertificateAlreadyIssuedException が throw されるはず');
        } catch (CertificateAlreadyIssuedException) {
            // 期待通り
        }

        Storage::disk('private')->assertMissing($certificate->pdf_path);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    private function assertGenerationFailsWithoutArtifacts(Enrollment $enrollment): void
    {
        try {
            app(IssueAction::class)($enrollment);
            $this->fail('CertificateGenerationException が throw されるはず');
        } catch (CertificateGenerationException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
