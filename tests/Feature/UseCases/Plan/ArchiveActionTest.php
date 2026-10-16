<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\ArchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_transition_when_latest_status_is_invalid(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();
        $updatedBy = $plan->updated_by_user_id;
        Plan::query()->whereKey($plan->id)->update(['status' => PlanStatus::Draft->value]);

        try {
            (new ArchiveAction)($plan, $admin);
            $this->fail('最新状態が条件を満たさない遷移は拒否します。');
        } catch (PlanInvalidTransitionException $exception) {
            $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
            $this->assertSame($updatedBy, $plan->fresh()->updated_by_user_id);
        }
    }

    public function test_archives_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $action = new ArchiveAction;
        $result = $action($plan, $admin);

        $this->assertSame(PlanStatus::Archived, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $action = new ArchiveAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }

    public function test_throws_when_already_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $action = new ArchiveAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }
}
