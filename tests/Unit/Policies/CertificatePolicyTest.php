<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certificate;
use App\Models\CertificationCoachAssignment;
use App\Models\User;
use App\Policies\CertificatePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CertificatePolicy の修了証ダウンロード権限をロール別に検証する。
 */
class CertificatePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = Certificate::factory()->create();

        $this->assertTrue(app(CertificatePolicy::class)->download($admin, $certificate));
    }

    public function test_student_can_download_only_own_certificate(): void
    {
        $certificate = Certificate::factory()->create();
        $owner = $certificate->user;
        $otherStudent = User::factory()->student()->create();

        $policy = app(CertificatePolicy::class);

        $this->assertTrue($policy->download($owner, $certificate));
        $this->assertFalse($policy->download($otherStudent, $certificate));
    }

    public function test_coach_can_download_only_certificate_of_currently_assigned_certification(): void
    {
        $certificate = Certificate::factory()->create();
        $assignedCoach = User::factory()->coach()->create();
        $unassignedCoach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certificate->certification_id,
            'user_id' => $assignedCoach->id,
        ]);
        CertificationCoachAssignment::factory()->unassigned()->create([
            'certification_id' => $certificate->certification_id,
            'user_id' => $unassignedCoach->id,
        ]);

        $policy = app(CertificatePolicy::class);

        $this->assertTrue($policy->download($assignedCoach, $certificate));
        $this->assertFalse($policy->download($unassignedCoach, $certificate));
        $this->assertFalse($policy->download($otherCoach, $certificate));
    }
}
