<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReply モデルのリレーションを検証する Unit テスト。
 * 2 リレーション (qaThread / user) を網羅する。user は退会済みユーザーも返すことを確認する。
 */
class QaReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_qa_thread_relation_returns_parent_thread(): void
    {
        $thread = QaThread::factory()->create();

        $reply = QaReply::factory()->create(['qa_thread_id' => $thread->id]);

        $this->assertTrue($reply->qaThread->is($thread));
    }

    public function test_user_relation_includes_withdrawn_author(): void
    {
        $coach = User::factory()->coach()->create();

        $reply = QaReply::factory()->create(['user_id' => $coach->id]);
        $coach->delete();

        $this->assertTrue($reply->fresh()->user->is($coach));
    }
}
