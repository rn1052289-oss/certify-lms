<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\User;
use App\Policies\CertificatePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_owner_can_download_existing_pdf_as_attachment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->createCertificate($student);
        $contents = '%PDF-1.4 existing certificate';
        Storage::disk('private')->put($certificate->pdf_path, $contents);

        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        $response->assertOk()->assertDownload('certificate-'.$certificate->id.'.pdf');
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($contents, $response->streamedContent());
        $this->assertDatabaseCount('certificates', 1);
    }

    public function test_graduated_owner_can_download_after_certification_is_archived(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->createCertificate($student);
        $certificate->certification->update(['status' => 'archived']);
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->actingAs($student)->get(route('certificates.download', $certificate))->assertOk();
    }

    public function test_admin_can_download_another_students_certificate(): void
    {
        $certificate = $this->createCertificate();
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('certificates.download', $certificate))->assertOk();
    }

    public function test_assigned_coach_can_download_but_cannot_after_assignment_is_removed(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->createCertificate();
        $assignment = CertificationCoachAssignment::factory()->create([
            'certification_id' => $certificate->certification_id,
            'user_id' => $coach->id,
            'unassigned_at' => null,
        ]);
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->actingAs($coach)->get(route('certificates.download', $certificate))->assertOk();
        $certificate->load('certification.coaches');
        $assignment->update(['unassigned_at' => now()]);

        $this->assertFalse((new CertificatePolicy)->download($coach, $certificate));
        $this->get(route('certificates.download', $certificate))->assertForbidden();
    }

    public function test_other_student_cannot_download(): void
    {
        $certificate = $this->createCertificate();
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->actingAs(User::factory()->student()->create())
            ->get(route('certificates.download', $certificate))->assertForbidden();
    }

    public function test_coach_assigned_to_another_certification_cannot_download(): void
    {
        $coach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create(['user_id' => $coach->id, 'unassigned_at' => null]);
        $certificate = $this->createCertificate();
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->actingAs($coach)->get(route('certificates.download', $certificate))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $certificate = $this->createCertificate();

        $this->get(route('certificates.download', $certificate))->assertRedirect(route('login'));
    }

    public function test_withdrawn_owner_cannot_authenticate_to_download(): void
    {
        $student = User::factory()->student()->withdrawn()->create(['password' => bcrypt('password')]);
        $certificate = $this->createCertificate($student);
        Storage::disk('private')->put($certificate->pdf_path, '%PDF-1.4 existing certificate');

        $this->from(route('login'))->post(route('login'), [
            'email' => $student->email,
            'password' => 'password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->get(route('certificates.download', $certificate))->assertRedirect(route('login'));
    }

    public function test_missing_pdf_is_not_generated_or_reissued(): void
    {
        $student = User::factory()->student()->create();
        $certificate = $this->createCertificate($student);
        $attributes = $certificate->fresh()->getRawOriginal();

        $this->actingAs($student)->get(route('certificates.download', $certificate))->assertNotFound();

        $this->assertSame($attributes, $certificate->fresh()->getRawOriginal());
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[DataProvider('invalidPaths')]
    public function test_invalid_pdf_path_cannot_expose_another_private_file(string $path): void
    {
        $student = User::factory()->student()->create();
        $certificate = $this->createCertificate($student);
        $certificate->update(['pdf_path' => $path]);
        Storage::disk('private')->put('secret.pdf', 'private information');
        Storage::disk('private')->put('certificates/secret.txt', 'private information');

        $this->actingAs($student)->get(route('certificates.download', $certificate))->assertNotFound();
    }

    public static function invalidPaths(): array
    {
        return [
            'outside certificate directory' => ['secret.pdf'],
            'parent path' => ['certificates/../secret.pdf'],
            'absolute path' => ['/secret.pdf'],
            'different extension' => ['certificates/secret.txt'],
            'backslash' => ['certificates\\..\\secret.pdf'],
        ];
    }

    private function createCertificate(?User $student = null): Certificate
    {
        $enrollment = Enrollment::factory()->for($student ?? User::factory()->student()->create())->passed()->create();

        return Certificate::factory()->forEnrollment($enrollment)->create();
    }
}
