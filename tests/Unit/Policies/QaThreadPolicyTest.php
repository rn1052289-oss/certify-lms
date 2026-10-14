<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThreadPolicy の閲覧・投稿・編集・解決切替・削除権限を検証する。
 */
class QaThreadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_any_and_create_follow_role_and_user_status(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $graduatedCoach = User::factory()->coach()->graduated()->create();

        $policy = app(QaThreadPolicy::class);

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->viewAny($student));
        $this->assertTrue($policy->viewAny($coach));
        $this->assertFalse($policy->viewAny($graduatedStudent));
        $this->assertFalse($policy->viewAny($graduatedCoach));

        $this->assertTrue($policy->create($student));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->create($admin));
        $this->assertFalse($policy->create($graduatedStudent));
        $this->assertFalse($policy->create($graduatedCoach));
    }

    public function test_student_can_view_only_published_threads(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $publishedThread = QaThread::factory()->create();
        $draftCertification = Certification::factory()->draft()->create();
        $archivedCertification = Certification::factory()->archived()->create();
        $draftThread = QaThread::factory()->create(['certification_id' => $draftCertification->id]);
        $archivedThread = QaThread::factory()->create(['certification_id' => $archivedCertification->id]);

        $policy = app(QaThreadPolicy::class);

        $this->assertTrue($policy->view($student, $publishedThread));
        $this->assertFalse($policy->view($student, $draftThread));
        $this->assertFalse($policy->view($student, $archivedThread));
        $this->assertFalse($policy->view($graduatedStudent, $publishedThread));
    }

    public function test_coach_can_view_only_assigned_published_threads(): void
    {
        $admin = User::factory()->admin()->create();
        $assignedCoach = User::factory()->coach()->inProgress()->create();
        $unassignedCoach = User::factory()->coach()->inProgress()->create();
        $formerCoach = User::factory()->coach()->inProgress()->create();

        $certification = Certification::factory()->published()->create();
        $draftCertification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);
        $draftThread = QaThread::factory()->create(['certification_id' => $draftCertification->id]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $assignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        CertificationCoachAssignment::factory()->unassigned()->create([
            'certification_id' => $certification->id,
            'user_id' => $formerCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $draftCertification->id,
            'user_id' => $assignedCoach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $policy = app(QaThreadPolicy::class);

        $this->assertTrue($policy->view($assignedCoach, $thread));
        $this->assertFalse($policy->view($unassignedCoach, $thread));
        $this->assertFalse($policy->view($formerCoach, $thread));
        $this->assertFalse($policy->view($assignedCoach, $draftThread));
    }

    public function test_only_owner_can_update_and_toggle_resolution_on_visible_thread(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        $thread = QaThread::factory()->create(['user_id' => $owner->id]);
        $draftCertification = Certification::factory()->draft()->create();
        $draftThread = QaThread::factory()->create([
            'certification_id' => $draftCertification->id,
            'user_id' => $owner->id,
        ]);

        $policy = app(QaThreadPolicy::class);

        foreach (['update', 'resolve', 'unresolve'] as $method) {
            $this->assertTrue($policy->{$method}($owner, $thread));
            $this->assertFalse($policy->{$method}($otherStudent, $thread));
            $this->assertFalse($policy->{$method}($coach, $thread));
            $this->assertFalse($policy->{$method}($admin, $thread));
            $this->assertFalse($policy->{$method}($owner, $draftThread));
        }
    }

    public function test_only_owner_without_replies_or_admin_can_delete_thread(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create(['user_id' => $owner->id]);

        $policy = app(QaThreadPolicy::class);

        $this->assertTrue($policy->delete($owner, $thread));
        $this->assertFalse($policy->delete($otherStudent, $thread));

        QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $this->assertFalse($policy->delete($owner, $thread));
        $this->assertTrue($policy->delete($admin, $thread));

        $draftCertification = Certification::factory()->draft()->create();
        $draftThread = QaThread::factory()->create([
            'certification_id' => $draftCertification->id,
            'user_id' => $owner->id,
        ]);

        $this->assertFalse($policy->delete($owner, $draftThread));
        $this->assertTrue($policy->delete($admin, $draftThread));
        $this->assertTrue($policy->view($admin, $draftThread));
    }
}
