<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use Database\Seeders\CertificateSeeder;
use Database\Seeders\CertificationCategorySeeder;
use Database\Seeders\CertificationSeeder;
use Database\Seeders\EnrollmentSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_both_seeders_generate_private_pdfs_with_the_original_issue_dates(): void
    {
        $this->seed([
            UserSeeder::class, PlanSeeder::class, CertificationCategorySeeder::class,
            CertificationSeeder::class, EnrollmentSeeder::class, CertificateSeeder::class,
        ]);

        $certificates = Certificate::with(['user', 'enrollment', 'certification.coaches'])->get();
        $this->assertGreaterThanOrEqual(2, $certificates->count());
        $this->assertTrue($certificates->contains(fn (Certificate $certificate) => $certificate->user->status->value === 'in_progress'));
        $this->assertTrue($certificates->contains(fn (Certificate $certificate) => $certificate->user->status->value === 'graduated'));
        $this->assertGreaterThanOrEqual(2, $certificates->flatMap(fn (Certificate $certificate) => $certificate->certification->coaches->modelKeys())->unique()->count());

        foreach ($certificates as $certificate) {
            Storage::disk('private')->assertExists($certificate->pdf_path);
            $this->assertStringStartsWith('%PDF-', Storage::disk('private')->get($certificate->pdf_path));
            $this->assertTrue($certificate->issued_at->equalTo($certificate->enrollment->passed_at));
            $this->actingAs($certificate->user)->get(route('certificates.download', $certificate))->assertOk()->assertDownload();
        }
    }

    public function test_seeding_new_certificates_does_not_repair_an_existing_missing_pdf(): void
    {
        $existing = Certificate::factory()->create();
        $before = $existing->fresh()->getRawOriginal();
        User::factory()->student()->graduated()->create();
        Certification::factory()->published()->create();

        $this->seed(CertificateSeeder::class);

        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        Storage::disk('private')->assertMissing($existing->pdf_path);
        $this->assertDatabaseCount('certificates', 2);
        $new = Certificate::whereKeyNot($existing->id)->sole();
        Storage::disk('private')->assertExists($new->pdf_path);
    }
}
