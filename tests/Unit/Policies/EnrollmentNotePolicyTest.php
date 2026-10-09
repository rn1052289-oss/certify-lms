<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentNotePolicy の閲覧・作成・編集・削除権限を検証する。
 */
class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_and_assigned_coach_can_view_and_create_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $assignedCoach = User::factory()->coach()->create();
        $unassignedCoach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $assignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $policy = app(EnrollmentNotePolicy::class);

        $this->assertTrue($policy->viewAny($admin, $enrollment));
        $this->assertTrue($policy->create($admin, $enrollment));
        $this->assertTrue($policy->viewAny($assignedCoach, $enrollment));
        $this->assertTrue($policy->create($assignedCoach, $enrollment));

        $this->assertFalse($policy->viewAny($unassignedCoach, $enrollment));
        $this->assertFalse($policy->create($unassignedCoach, $enrollment));
        $this->assertFalse($policy->viewAny($student, $enrollment));
        $this->assertFalse($policy->create($student, $enrollment));
    }

    public function test_only_admin_and_assigned_note_author_can_update_and_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $author = User::factory()->coach()->create();
        $otherAssignedCoach = User::factory()->coach()->create();
        $unassignedCoach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $author->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $otherAssignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $author->id,
        ]);

        $policy = app(EnrollmentNotePolicy::class);

        $this->assertTrue($policy->update($admin, $note));
        $this->assertTrue($policy->delete($admin, $note));
        $this->assertTrue($policy->update($author, $note));
        $this->assertTrue($policy->delete($author, $note));

        foreach ([$otherAssignedCoach, $unassignedCoach, $student] as $user) {
            $this->assertFalse($policy->update($user, $note));
            $this->assertFalse($policy->delete($user, $note));
        }

        $unassignedCoachNote = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $unassignedCoach->id,
        ]);

        $this->assertFalse($policy->update($unassignedCoach, $unassignedCoachNote));
        $this->assertFalse($policy->delete($unassignedCoach, $unassignedCoachNote));
    }

    public function test_soft_deleted_enrollment_denies_all_note_operations(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
        ]);

        $enrollment->delete();

        $policy = app(EnrollmentNotePolicy::class);

        foreach ([$admin, $coach] as $user) {
            $this->assertFalse($policy->viewAny($user, $enrollment));
            $this->assertFalse($policy->create($user, $enrollment));
            $this->assertFalse($policy->update($user, $note));
            $this->assertFalse($policy->delete($user, $note));
        }
    }
}
