<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReplyPolicy の投稿・編集・削除権限を検証する。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_reply_only_to_published_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();
        $draftCertification = Certification::factory()->draft()->create();
        $draftThread = QaThread::factory()->create(['certification_id' => $draftCertification->id]);

        $policy = app(QaReplyPolicy::class);

        $this->assertTrue($policy->create($student, $thread));
        $this->assertFalse($policy->create($graduatedStudent, $thread));
        $this->assertFalse($policy->create($admin, $thread));
        $this->assertFalse($policy->create($student, $draftThread));
    }

    public function test_coach_can_create_reply_only_to_assigned_published_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $assignedCoach = User::factory()->coach()->inProgress()->create();
        $otherCoach = User::factory()->coach()->inProgress()->create();
        $formerCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);

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

        $policy = app(QaReplyPolicy::class);

        $this->assertTrue($policy->create($assignedCoach, $thread));
        $this->assertFalse($policy->create($otherCoach, $thread));
        $this->assertFalse($policy->create($formerCoach, $thread));
    }

    public function test_only_reply_author_with_access_can_update(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $graduatedStudent = User::factory()->student()->graduated()->create();
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
        ]);
        $graduatedReply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $graduatedStudent->id,
        ]);
        $draftCertification = Certification::factory()->draft()->create();
        $draftThread = QaThread::factory()->create(['certification_id' => $draftCertification->id]);
        $draftReply = QaReply::factory()->create([
            'qa_thread_id' => $draftThread->id,
            'user_id' => $author->id,
        ]);

        $policy = app(QaReplyPolicy::class);

        $this->assertTrue($policy->update($author, $reply));
        $this->assertFalse($policy->update($otherStudent, $reply));
        $this->assertFalse($policy->update($admin, $reply));
        $this->assertFalse($policy->update($graduatedStudent, $graduatedReply));
        $this->assertFalse($policy->update($author, $draftReply));
    }

    public function test_coach_cannot_update_reply_after_assignment_ends(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);
        $assignment = CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
        ]);

        $policy = app(QaReplyPolicy::class);

        $this->assertTrue($policy->update($coach, $reply));

        $assignment->update(['unassigned_at' => now()]);

        $this->assertFalse($policy->update($coach, $reply->fresh()));
    }

    public function test_only_author_or_admin_can_delete_reply(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
        ]);
        $draftCertification = Certification::factory()->draft()->create();
        $draftThread = QaThread::factory()->create(['certification_id' => $draftCertification->id]);
        $draftReply = QaReply::factory()->create([
            'qa_thread_id' => $draftThread->id,
            'user_id' => $author->id,
        ]);

        $policy = app(QaReplyPolicy::class);

        $this->assertTrue($policy->delete($author, $reply));
        $this->assertFalse($policy->delete($otherStudent, $reply));
        $this->assertFalse($policy->delete($author, $draftReply));
        $this->assertTrue($policy->delete($admin, $reply));
        $this->assertTrue($policy->delete($admin, $draftReply));
        $this->assertFalse($policy->update($admin, $reply));
    }
}
