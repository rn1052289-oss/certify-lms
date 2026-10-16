<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\ArchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_transition_when_latest_status_is_invalid(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();
        $updatedBy = $plan->updated_by_user_id;
        MeetingPack::query()->whereKey($plan->id)->update(['status' => MeetingPackStatus::Draft->value]);

        try {
            (new ArchiveAction)($plan, $admin);
            $this->fail('最新状態が条件を満たさない遷移は拒否します。');
        } catch (MeetingPackInvalidTransitionException $exception) {
            $this->assertSame(MeetingPackStatus::Draft, $plan->fresh()->status);
            $this->assertSame($updatedBy, $plan->fresh()->updated_by_user_id);
        }
    }

    public function test_archives_published_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $result = (new ArchiveAction)($plan, $admin);

        $this->assertSame(MeetingPackStatus::Archived, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->expectException(MeetingPackInvalidTransitionException::class);

        (new ArchiveAction)($plan, $admin);
    }

    public function test_throws_when_already_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $this->expectException(MeetingPackInvalidTransitionException::class);

        (new ArchiveAction)($plan, $admin);
    }
}
