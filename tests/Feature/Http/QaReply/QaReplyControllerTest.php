<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaReply;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問掲示板の回答投稿・編集・削除・入力検証・認可の HTTP 統合テスト。
 */
class QaReplyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_reply_to_another_students_question(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create(['user_id' => $otherStudent->id]);
        $otherThread = QaThread::factory()->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => 'まずは過去問を解くことをおすすめします。',
            'user_id' => $otherStudent->id,
            'qa_thread_id' => $otherThread->id,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => 'まずは過去問を解くことをおすすめします。',
        ]);
        $this->assertDatabaseCount('qa_replies', 1);
    }

    public function test_assigned_coach_can_reply_to_question(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);

        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '担当コーチからの回答です。',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
            'body' => '担当コーチからの回答です。',
        ]);
    }

    public function test_unassigned_coach_cannot_reply_to_question(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $thread = QaThread::factory()->create();

        $response = $this->actingAs($coach)->post(route('qa-board.replies.store', $thread), [
            'body' => '担当外の資格への回答です。',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_student_cannot_reply_to_draft_certification_question(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '非公開資格への回答です。',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_admin_cannot_post_reply(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();

        $response = $this->actingAs($admin)->post(route('qa-board.replies.store', $thread), [
            'body' => '管理者からの回答です。',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_reply_requires_body_and_rejects_over_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => str_repeat('a', 5001),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('qa_replies', 0);
    }

    public function test_reply_accepts_body_with_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $body = str_repeat('a', 5000);

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => $body,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => $body,
        ]);
    }

    public function test_author_can_view_reply_edit_form(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '編集前の回答です。',
        ]);

        $response = $this->actingAs($student)->get(route('qa-board.replies.edit', [
            'thread' => $thread,
            'reply' => $reply,
        ]));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.reply-edit');
        $response->assertViewHas('reply');
        $response->assertSee('編集前の回答です。');
    }

    public function test_author_can_update_reply_body_only(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $otherThread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '更新前の回答です。',
        ]);

        $response = $this->actingAs($student)->patch(route('qa-board.replies.update', [
            'thread' => $thread,
            'reply' => $reply,
        ]), [
            'body' => '更新後の回答です。',
            'qa_thread_id' => $otherThread->id,
            'user_id' => $otherStudent->id,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '更新後の回答です。',
        ]);
    }

    public function test_update_validates_body_and_accepts_5000_characters(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '変更前の回答です。',
        ]);
        $parameters = ['thread' => $thread, 'reply' => $reply];

        $response = $this->actingAs($student)->patch(route('qa-board.replies.update', $parameters), ['body' => '']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');

        $response = $this->actingAs($student)->patch(route('qa-board.replies.update', $parameters), ['body' => str_repeat('a', 5001)]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'body' => '変更前の回答です。']);

        $body = str_repeat('a', 5000);
        $response = $this->actingAs($student)->patch(route('qa-board.replies.update', $parameters), ['body' => $body]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'body' => $body]);
    }

    public function test_other_student_cannot_edit_update_or_delete_reply(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '他人の回答です。',
        ]);

        $parameters = ['thread' => $thread, 'reply' => $reply];

        $this->actingAs($otherStudent)->get(route('qa-board.replies.edit', $parameters))->assertStatus(403);
        $this->actingAs($otherStudent)->patch(route('qa-board.replies.update', $parameters), ['body' => '不正更新'])->assertStatus(403);
        $this->actingAs($otherStudent)->delete(route('qa-board.replies.destroy', $parameters))->assertStatus(403);

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '他人の回答です。',
        ]);
    }

    public function test_author_can_delete_own_reply(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
        ]);

        $response = $this->actingAs($student)->delete(route('qa-board.replies.destroy', [
            'thread' => $thread,
            'reply' => $reply,
        ]));

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_assigned_coach_can_edit_and_delete_own_reply(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $coach->id,
            'body' => '編集前のコーチ回答です。',
        ]);

        $parameters = ['thread' => $thread, 'reply' => $reply];

        $response = $this->actingAs($coach)->patch(route('qa-board.replies.update', $parameters), [
            'body' => '編集後のコーチ回答です。',
        ]);

        $response->assertStatus(302);
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '編集後のコーチ回答です。',
        ]);

        $response = $this->actingAs($coach)->delete(route('qa-board.replies.destroy', $parameters));

        $response->assertStatus(302);
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }

    public function test_reply_cannot_be_accessed_under_different_thread(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $otherThread = QaThread::factory()->create();
        $reply = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $student->id,
            'body' => '元の回答です。',
        ]);

        $parameters = ['thread' => $otherThread, 'reply' => $reply];

        $this->actingAs($student)->get(route('qa-board.replies.edit', $parameters))->assertStatus(404);
        $this->actingAs($student)->patch(route('qa-board.replies.update', $parameters), ['body' => '不正更新'])->assertStatus(404);
        $this->actingAs($student)->delete(route('qa-board.replies.destroy', $parameters))->assertStatus(404);

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'body' => '元の回答です。',
        ]);
    }

    public function test_graduated_student_cannot_post_reply(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $thread = QaThread::factory()->create();

        $response = $this->actingAs($student)->post(route('qa-board.replies.store', $thread), [
            'body' => '卒業済み受講生からの回答です。',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('qa_replies', 0);
    }
}
