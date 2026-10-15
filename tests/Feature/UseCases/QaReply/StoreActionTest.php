<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaReply;

use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use App\UseCases\QaReply\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Q&A 回答投稿後の質問者への通知を検証する。
 */
class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_notifies_questioner_but_not_author(): void
    {
        Notification::fake();

        $questioner = User::factory()->student()->inProgress()->create();
        $author = User::factory()->coach()->inProgress()->create();
        $thread = QaThread::factory()->create(['user_id' => $questioner->id]);

        $action = $this->app->make(StoreAction::class);
        $reply = $action($thread, $author, ['body' => '回答しました。']);

        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
        ]);

        Notification::assertSentTo($questioner, QaReplyReceivedNotification::class, function ($notification, $channels) use ($questioner, $thread): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($questioner)['url'] === route('qa-board.show', $thread);
        });
        Notification::assertNotSentTo($author, QaReplyReceivedNotification::class);
    }

    public function test_self_reply_does_not_send_notification(): void
    {
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $thread = QaThread::factory()->create(['user_id' => $student->id]);

        $action = $this->app->make(StoreAction::class);
        $reply = $action($thread, $student, ['body' => '自分の質問への補足回答です。']);

        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
        Notification::assertNothingSent();
    }

    public function test_graduated_questioner_does_not_receive_notification(): void
    {
        Notification::fake();

        $questioner = User::factory()->student()->graduated()->create();
        $author = User::factory()->coach()->inProgress()->create();
        $thread = QaThread::factory()->create(['user_id' => $questioner->id]);

        $action = $this->app->make(StoreAction::class);
        $reply = $action($thread, $author, ['body' => '卒業済みの質問者への回答です。']);

        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
        Notification::assertNothingSent();
    }

    public function test_deleted_questioner_does_not_receive_notification(): void
    {
        Notification::fake();

        $questioner = User::factory()->student()->inProgress()->create();
        $author = User::factory()->coach()->inProgress()->create();
        $thread = QaThread::factory()->create(['user_id' => $questioner->id]);
        $questioner->delete();

        $action = $this->app->make(StoreAction::class);
        $reply = $action($thread, $author, ['body' => '退会済みの質問者への回答です。']);

        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);
        Notification::assertNothingSent();
    }
}
