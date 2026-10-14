<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\IndexAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問掲示板の一覧取得・公開範囲・検索・件数・ページネーションを検証する。
 */
class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_published_certification_threads(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $published = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $archived = Certification::factory()->archived()->create();

        $visible = QaThread::factory()->create(['certification_id' => $published->id]);
        QaThread::factory()->create(['certification_id' => $draft->id]);
        QaThread::factory()->create(['certification_id' => $archived->id]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($student, []);

        $this->assertSame(1, $result['threads']->total());
        $this->assertSame($visible->id, $result['threads']->items()[0]->id);
        $this->assertCount(1, $result['certifications']);
        $this->assertTrue($result['certifications']->first()->is($published));
    }

    public function test_coach_sees_only_currently_assigned_published_certification_threads(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = Certification::factory()->published()->create();
        $notAssigned = Certification::factory()->published()->create();
        $formerAssignment = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();

        $visible = QaThread::factory()->create(['certification_id' => $assigned->id]);
        QaThread::factory()->create(['certification_id' => $notAssigned->id]);
        QaThread::factory()->create(['certification_id' => $formerAssignment->id]);
        QaThread::factory()->create(['certification_id' => $draft->id]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $assigned->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        CertificationCoachAssignment::factory()->unassigned()->create([
            'certification_id' => $formerAssignment->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $draft->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
        ]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($coach, []);

        $this->assertSame(1, $result['threads']->total());
        $this->assertSame($visible->id, $result['threads']->items()[0]->id);
        $this->assertCount(1, $result['certifications']);
        $this->assertTrue($result['certifications']->first()->is($assigned));
    }

    public function test_admin_sees_threads_for_all_certification_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $published = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $archived = Certification::factory()->archived()->create();

        QaThread::factory()->create(['certification_id' => $published->id]);
        QaThread::factory()->create(['certification_id' => $draft->id]);
        QaThread::factory()->create(['certification_id' => $archived->id]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($admin, []);

        $this->assertSame(3, $result['threads']->total());
        $this->assertCount(3, $result['certifications']);
    }

    public function test_filters_by_certification_status_and_body_keyword(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();

        $matching = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'body' => '模試の復習方法について教えてください。',
        ]);

        QaThread::factory()->resolved()->create([
            'certification_id' => $certification->id,
            'body' => '模試の復習方法について教えてください。',
        ]);

        QaThread::factory()->create([
            'certification_id' => $otherCertification->id,
            'body' => '模試の復習方法について教えてください。',
        ]);

        QaThread::factory()->create([
            'certification_id' => $certification->id,
            'title' => '模試について',
            'body' => '学習計画の立て方について教えてください。',
        ]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($student, [
            'certification_id' => $certification->id,
            'status' => QaThreadStatus::Unresolved->value,
            'keyword' => '模試',
        ]);

        $this->assertSame(1, $result['threads']->total());
        $this->assertSame($matching->id, $result['threads']->items()[0]->id);
    }

    public function test_loads_display_relations_reply_counts_and_newest_first(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $older = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'created_at' => now()->subDays(2),
        ]);

        $newer = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'created_at' => now()->subDay(),
        ]);

        QaReply::factory()->count(2)->create([
            'qa_thread_id' => $newer->id,
            'user_id' => $student->id,
        ]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($student, []);
        $threads = $result['threads']->items();

        $this->assertSame($newer->id, $threads[0]->id);
        $this->assertSame($older->id, $threads[1]->id);
        $this->assertSame(2, $threads[0]->replies_count);
        $this->assertSame(0, $threads[1]->replies_count);
        $this->assertTrue($threads[0]->relationLoaded('certification'));
        $this->assertTrue($threads[0]->relationLoaded('user'));
    }

    public function test_paginates_threads_twenty_per_page(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        QaThread::factory()->count(21)->create([
            'certification_id' => $certification->id,
            'user_id' => $student->id,
        ]);

        $action = $this->app->make(IndexAction::class);
        $result = $action($student, []);

        $this->assertCount(20, $result['threads']->items());
        $this->assertSame(21, $result['threads']->total());
        $this->assertSame(20, $result['threads']->perPage());
        $this->assertSame(2, $result['threads']->lastPage());
    }
}
