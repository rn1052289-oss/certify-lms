<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\UseCases\QaThread\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 質問詳細取得 ShowAction の関連データ・回答順序・回答件数を検証する。
 */
class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_detail_relations_replies_in_chronological_order_and_reply_count(): void
    {
        $author = User::factory()->student()->inProgress()->create();
        $replyAuthor = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $thread = QaThread::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $author->id,
        ]);

        $newer = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $replyAuthor->id,
            'body' => '後に投稿された回答です。',
            'created_at' => now()->subDay(),
        ]);
        $older = QaReply::factory()->create([
            'qa_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => '先に投稿された回答です。',
            'created_at' => now()->subDays(2),
        ]);

        $action = $this->app->make(ShowAction::class);
        $result = $action($thread);

        $this->assertTrue($result->relationLoaded('certification'));
        $this->assertTrue($result->relationLoaded('user'));
        $this->assertTrue($result->relationLoaded('replies'));

        $this->assertSame($certification->id, $result->certification->id);
        $this->assertSame($author->id, $result->user->id);

        $this->assertSame(2, $result->replies_count);
        $this->assertCount(2, $result->replies);
        $this->assertSame([$older->id, $newer->id], $result->replies->pluck('id')->all());

        $this->assertTrue($result->replies[0]->relationLoaded('user'));
        $this->assertTrue($result->replies[1]->relationLoaded('user'));
        $this->assertSame($author->id, $result->replies[0]->user->id);
        $this->assertSame($replyAuthor->id, $result->replies[1]->user->id);
    }
}
