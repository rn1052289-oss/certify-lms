<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentGoalPolicy の閲覧・操作権限を検証する。
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_allows_admin_owner_and_assigned_coach_only(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $assignedCoach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $assignedCoach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $enrollment = Enrollment::factory()
            ->for($owner)
            ->for($certification)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create();

        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->view($admin, $goal));
        $this->assertTrue($policy->view($owner, $goal));
        $this->assertTrue($policy->view($assignedCoach, $goal));
        $this->assertFalse($policy->view($otherStudent, $goal));
        $this->assertFalse($policy->view($otherCoach, $goal));
    }

    public function test_only_owner_student_can_create_goal(): void
    {
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $enrollment = Enrollment::factory()
            ->for($owner)
            ->learning()
            ->create();

        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->create($owner, $enrollment));
        $this->assertFalse($policy->create($otherStudent, $enrollment));
        $this->assertFalse($policy->create($coach, $enrollment));
        $this->assertFalse($policy->create($admin, $enrollment));
    }

    public function test_only_owner_student_can_modify_goal(): void
    {
        $owner = User::factory()->student()->create();
        $otherStudent = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $enrollment = Enrollment::factory()
            ->for($owner)
            ->learning()
            ->create();

        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create();

        $policy = app(EnrollmentGoalPolicy::class);

        $this->assertTrue($policy->update($owner, $goal));
        $this->assertTrue($policy->delete($owner, $goal));
        $this->assertTrue($policy->markAchieved($owner, $goal));
        $this->assertTrue($policy->unmarkAchieved($owner, $goal));

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->assertFalse($policy->update($user, $goal));
            $this->assertFalse($policy->delete($user, $goal));
            $this->assertFalse($policy->markAchieved($user, $goal));
            $this->assertFalse($policy->unmarkAchieved($user, $goal));
        }
    }
}
