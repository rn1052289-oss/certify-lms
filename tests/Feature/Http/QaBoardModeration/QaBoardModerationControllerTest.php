<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoardModeration;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による質問掲示板の閲覧・モデレーション削除・認可の HTTP 統合テスト。
 */
class QaBoardModerationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_threads_of_published_draft_and_archived_certifications(): void
    {
        $admin = User::factory()->admin()->create();
        $published = Certification::factory()->published()->create();
        $draft = Certification::factory()->draft()->create();
        $archived = Certification::factory()->archived()->create();

        QaThread::factory()->create(['certification_id' => $published->id, 'title' => '公開済みの質問']);
        QaThread::factory()->create(['certification_id' => $draft->id, 'title' => '下書き資格の質問']);
        QaThread::factory()->create(['certification_id' => $archived->id, 'title' => '終了資格の質問']);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.index'));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.index');
        $response->assertSee('公開済みの質問');
        $response->assertSee('下書き資格の質問');
        $response->assertSee('終了資格の質問');
        $response->assertSee('質問掲示板モデレーション');
    }

    public function test_admin_can_view_draft_thread_and_replies(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $draft->id, 'title' => '非公開資格への質問']);
        QaReply::factory()->create(['qa_thread_id' => $thread->id, 'body' => '非公開資格への回答']);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.show', $thread));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.show');
        $response->assertSee('非公開資格への質問');
        $response->assertSee('非公開資格への回答');
        $response->assertSee('管理者は閲覧 + モデレーション削除のみ可能');
    }

    public function test_admin_can_delete_thread_with_replies_physically(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Certification::factory()->draft()->create();
        $thread = QaThread::factory()->create(['certification_id' => $draft->id]);
        $replies = QaReply::factory()->count(2)->create(['qa_thread_id' => $thread->id]);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.destroy', $thread));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.qa-board.index'));
        $this->assertDatabaseMissing('qa_threads', ['id' => $thread->id]);
        foreach ($replies as $reply) {
            $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        }
    }

    public function test_admin_can_delete_reply_without_deleting_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.replies.destroy', [
            'thread' => $thread,
            'reply' => $reply,
        ]));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.qa-board.show', $thread));
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
    }

    public function test_admin_cannot_delete_reply_using_different_parent_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create();
        $otherThread = QaThread::factory()->create();
        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $response = $this->actingAs($admin)->delete(route('admin.qa-board.replies.destroy', [
            'thread' => $otherThread,
            'reply' => $reply,
        ]));

        $response->assertStatus(404);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id, 'qa_thread_id' => $thread->id]);
    }

    public function test_student_cannot_access_admin_moderation_routes(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);
        $parameters = ['thread' => $thread, 'reply' => $reply];

        $this->actingAs($student)->get(route('admin.qa-board.index'))->assertStatus(403);
        $this->actingAs($student)->get(route('admin.qa-board.show', $thread))->assertStatus(403);
        $this->actingAs($student)->delete(route('admin.qa-board.destroy', $thread))->assertStatus(403);
        $this->actingAs($student)->delete(route('admin.qa-board.replies.destroy', $parameters))->assertStatus(403);

        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_coach_cannot_access_admin_moderation_routes(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $thread = QaThread::factory()->create();
        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);
        $parameters = ['thread' => $thread, 'reply' => $reply];

        $this->actingAs($coach)->get(route('admin.qa-board.index'))->assertStatus(403);
        $this->actingAs($coach)->get(route('admin.qa-board.show', $thread))->assertStatus(403);
        $this->actingAs($coach)->delete(route('admin.qa-board.destroy', $thread))->assertStatus(403);
        $this->actingAs($coach)->delete(route('admin.qa-board.replies.destroy', $parameters))->assertStatus(403);

        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id]);
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
    }

    public function test_admin_cannot_use_student_routes_to_modify_thread(): void
    {
        $admin = User::factory()->admin()->create();
        $thread = QaThread::factory()->create(['title' => '変更されない質問']);

        $this->actingAs($admin)->get(route('qa-board.create'))->assertStatus(403);
        $this->actingAs($admin)->post(route('qa-board.store'), [
            'certification_id' => $thread->certification_id,
            'title' => '管理者による投稿',
            'body' => '不正な投稿',
        ])->assertStatus(403);
        $this->actingAs($admin)->patch(route('qa-board.update', $thread), [
            'title' => '不正な更新',
            'body' => '不正な本文',
        ])->assertStatus(403);
        $this->actingAs($admin)->post(route('qa-board.resolve', $thread))->assertStatus(403);
        $this->actingAs($admin)->delete(route('qa-board.destroy', $thread))->assertStatus(403);

        $this->assertDatabaseHas('qa_threads', ['id' => $thread->id, 'title' => '変更されない質問']);
        $this->assertDatabaseCount('qa_threads', 1);
    }

    public function test_admin_can_filter_unpublished_threads_by_certification_status_and_keyword(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Certification::factory()->draft()->create();
        $published = Certification::factory()->published()->create();

        QaThread::factory()->resolved()->create([
            'certification_id' => $draft->id,
            'title' => '検索に一致する質問',
            'body' => '復習ポイントを教えてください。',
        ]);
        QaThread::factory()->create([
            'certification_id' => $draft->id,
            'title' => '未解決の別質問',
            'body' => '復習ポイントについて困っています。',
        ]);
        QaThread::factory()->resolved()->create([
            'certification_id' => $published->id,
            'title' => '別資格の解決済み質問',
            'body' => '復習ポイントを確認しました。',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.index', [
            'certification_id' => $draft->id,
            'status' => 'resolved',
            'keyword' => '復習ポイント',
        ]));

        $response->assertStatus(200);
        $response->assertSee('検索に一致する質問');
        $response->assertDontSee('未解決の別質問');
        $response->assertDontSee('別資格の解決済み質問');
    }
}
