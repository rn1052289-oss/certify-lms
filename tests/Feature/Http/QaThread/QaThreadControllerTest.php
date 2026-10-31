<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * 質問掲示板の閲覧・投稿・編集・削除・解決状態変更を確認する HTTP 統合テスト。
 */
class QaThreadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createThread(User $author, Certification $certification): QaThread
    {
        return QaThread::factory()->create([
            'user_id' => $author->id,
            'certification_id' => $certification->id,
        ]);
    }

    /**
     * 投稿者・資格・回答がそれぞれ別のスレッドを作る。関連を1件ずつ読むと SQL 本数が増えるデータにする。
     */
    private function createThreadsWithRelations(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $author = User::factory()->student()->inProgress()->create();
            $thread = $this->createThread($author, Certification::factory()->published()->create());

            QaReply::factory()->count(2)->create([
                'qa_thread_id' => $thread->id,
                'user_id' => $author->id,
            ]);
        }
    }

    /**
     * 一覧を1回開いたときの SQL 本数を数える。前のリクエストで読み込んだ関連を持ち越さないよう、閲覧者は読み直す。
     */
    private function countIndexQueries(User $viewer, int $expectedTotal): int
    {
        $viewer = $viewer->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($viewer)->get(route('qa-board.index'));

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertSame($expectedTotal, $response->viewData('threads')->total());
        $this->assertCount(min($expectedTotal, 20), $response->viewData('threads')->items());

        return $queryCount;
    }

    public function test_student_can_view_index_and_create_form(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        Certification::factory()->published()->create();

        $this->actingAs($student)->get(route('qa-board.index'))->assertStatus(200)->assertViewIs('qa-thread.index');
        $this->actingAs($student)->get(route('qa-board.create'))->assertStatus(200)->assertViewIs('qa-thread.create');
    }

    public function test_student_can_post_to_unenrolled_published_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '学習方法について',
            'body' => 'この資格の学習方法を教えてください。',
            'user_id' => $otherStudent->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('qa_threads', [
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => '学習方法について',
            'body' => 'この資格の学習方法を教えてください。',
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
        $this->assertDatabaseCount('qa_threads', 1);
    }

    public function test_store_validates_required_fields_and_max_lengths(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), []);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['certification_id', 'title', 'body']);

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => str_repeat('a', 201),
            'body' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['title', 'body']);
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_store_accepts_title_with_200_characters_and_body_with_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $title = str_repeat('a', 200);
        $body = str_repeat('b', 5000);

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => $title,
            'body' => $body,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('qa_threads', [
            'certification_id' => $certification->id,
            'user_id' => $student->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    public function test_student_cannot_post_to_draft_certification(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();

        $response = $this->actingAs($student)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => '資格について',
            'body' => '学習方法を教えてください。',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('certification_id');
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_student_can_view_another_students_published_question(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $viewer = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($viewer)->get(route('qa-board.show', $thread));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.show');
        $response->assertSee($thread->title);
    }

    public function test_student_cannot_view_question_for_draft_certification(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $viewer = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $thread = $this->createThread($author, $certification);

        $this->actingAs($viewer)->get(route('qa-board.show', $thread))->assertStatus(403);
    }

    public function test_author_can_view_edit_form(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($author)->get(route('qa-board.edit', $thread));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.edit');
        $response->assertViewHas('thread');
        $response->assertSee($thread->title);
        $response->assertSee($thread->body);
    }

    public function test_author_can_update_title_and_body_only(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $otherCertification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => '更新後のタイトル',
            'body' => '更新後の本文',
            'certification_id' => $otherCertification->id,
            'user_id' => $otherStudent->id,
            'status' => QaThreadStatus::Resolved->value,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'certification_id' => $certification->id,
            'user_id' => $author->id,
            'title' => '更新後のタイトル',
            'body' => '更新後の本文',
            'status' => QaThreadStatus::Unresolved->value,
        ]);
    }

    public function test_update_validates_required_fields_and_max_lengths(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => '',
            'body' => '',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['title', 'body']);

        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => str_repeat('a', 201),
            'body' => str_repeat('b', 5001),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['title', 'body']);
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => $thread->title,
            'body' => $thread->body,
        ]);
    }

    public function test_update_accepts_title_with_200_characters_and_body_with_5000_characters(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);
        $title = str_repeat('あ', 200);
        $body = str_repeat('い', 5000);

        $response = $this->actingAs($author)->patch(route('qa-board.update', $thread), [
            'title' => $title,
            'body' => $body,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    public function test_other_student_cannot_edit_or_update_question(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $this->actingAs($otherStudent)->get(route('qa-board.edit', $thread))->assertStatus(403);

        $response = $this->actingAs($otherStudent)->patch(route('qa-board.update', $thread), [
            'title' => '不正な更新',
            'body' => '他人の質問を変更',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id, 'title' => '不正な更新']);
    }

    public function test_author_can_delete_question_without_replies(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($author)->delete(route('qa-board.destroy', $thread));

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
    }

    public function test_author_cannot_delete_question_with_replies(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);
        QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $response = $this->actingAs($author)->delete(route('qa-board.destroy', $thread));

        $response->assertStatus(403);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_author_cannot_delete_question_when_reply_arrives_after_authorization(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $thread = $this->createThread($author, Certification::factory()->published()->create());
        $reply = null;

        Gate::after(function (User $user, string $ability, ?bool $result, array $arguments) use ($thread, &$reply): void {
            if ($ability === 'delete' && $result === true && ($arguments[0] ?? null) instanceof QaThread && $reply === null) {
                $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);
            }
        });

        $this->actingAs($author)->delete(route('qa-board.destroy', $thread))->assertForbidden();

        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_author_can_resolve_and_unresolve_question(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = $this->createThread($author, $certification);

        $response = $this->actingAs($author)->post(route('qa-board.resolve', $thread));

        $response->assertStatus(302);
        $this->assertSame(QaThreadStatus::Resolved, $thread->fresh()->status);
        $this->assertNotNull($thread->fresh()->resolved_at);

        $response = $this->actingAs($author)->post(route('qa-board.unresolve', $thread));

        $response->assertStatus(302);
        $this->assertSame(QaThreadStatus::Unresolved, $thread->fresh()->status);
        $this->assertNull($thread->fresh()->resolved_at);
    }

    public function test_coach_cannot_create_question(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        $this->actingAs($coach)->get(route('qa-board.create'))->assertStatus(403);

        $response = $this->actingAs($coach)->post(route('qa-board.store'), [
            'certification_id' => $certification->id,
            'title' => 'コーチからの質問',
            'body' => '投稿できないことを確認します。',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('qa_threads', 0);
    }

    public function test_graduated_student_cannot_access_question_board(): void
    {
        $student = User::factory()->student()->graduated()->create();

        $this->actingAs($student)->get(route('qa-board.index'))->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login_from_question_board(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $thread = $this->createThread($author, Certification::factory()->published()->create());

        $this->get(route('qa-board.index'))->assertRedirect(route('login'));
        $this->get(route('qa-board.show', $thread))->assertRedirect(route('login'));
        $this->post(route('qa-board.store'), ['title' => 'ゲスト', 'body' => 'ゲスト'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('qa_threads', 1);
    }

    public function test_index_query_count_does_not_grow_with_thread_count(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->createThreadsWithRelations(3);
        $fewThreadsQueryCount = $this->countIndexQueries($student, 3);

        $this->createThreadsWithRelations(27);
        $manyThreadsQueryCount = $this->countIndexQueries($student, 30);

        $this->assertSame($fewThreadsQueryCount, $manyThreadsQueryCount);
    }

    public function test_index_accepts_keyword_with_100_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $keyword = str_repeat('あ', 100);
        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'certification_id' => $certification->id,
            'title' => $keyword,
        ]);

        $response = $this->actingAs($student)->get(route('qa-board.index', ['keyword' => $keyword]));

        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
        $response->assertSee($thread->title);
    }

    public function test_index_rejects_invalid_filters(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $response = $this->actingAs($student)->get(route('qa-board.index', [
            'certification_id' => 'invalid',
            'status' => 'invalid',
            'keyword' => str_repeat('a', 101),
        ]));

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['certification_id', 'status', 'keyword']);
    }
}
