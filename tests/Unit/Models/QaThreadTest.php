<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QaThread モデルのリレーション・Cast を検証する Unit テスト。
 * 3 リレーション (certification / user / replies) + 2 cast (status / resolved_at) を網羅する。
 * user は退会済みユーザーも返すことを確認する。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_certification_relation_returns_target_certification(): void
    {
        $certification = Certification::factory()->published()->create();

        $thread = QaThread::factory()->create(['certification_id' => $certification->id]);

        $this->assertTrue($thread->certification->is($certification));
    }

    public function test_user_relation_includes_withdrawn_author(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $thread = QaThread::factory()->create(['user_id' => $student->id]);
        $student->delete();

        $this->assertTrue($thread->fresh()->user->is($student));
    }

    public function test_replies_relation_returns_only_own_replies(): void
    {
        $thread = QaThread::factory()->create();
        $replies = QaReply::factory()->count(2)->create(['qa_thread_id' => $thread->id]);
        QaReply::factory()->create();

        $this->assertEqualsCanonicalizing(
            $replies->pluck('id')->all(),
            $thread->replies->pluck('id')->all(),
        );
    }

    public function test_status_and_resolved_at_are_cast(): void
    {
        $unresolved = QaThread::factory()->create()->fresh();
        $resolved = QaThread::factory()->resolved()->create()->fresh();

        $this->assertSame(QaThreadStatus::Unresolved, $unresolved->status);
        $this->assertNull($unresolved->resolved_at);
        $this->assertSame(QaThreadStatus::Resolved, $resolved->status);
        $this->assertInstanceOf(Carbon::class, $resolved->resolved_at);
    }
}
