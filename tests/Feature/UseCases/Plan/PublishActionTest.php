<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\PublishAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_transition_when_latest_status_is_invalid(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();
        $updatedBy = $plan->updated_by_user_id;
        Plan::query()->whereKey($plan->id)->update(['status' => PlanStatus::Archived->value]);

        try {
            (new PublishAction)($plan, $admin);
            $this->fail('最新状態が条件を満たさない遷移は拒否します。');
        } catch (PlanInvalidTransitionException $exception) {
            $this->assertSame(PlanStatus::Archived, $plan->fresh()->status);
            $this->assertSame($updatedBy, $plan->fresh()->updated_by_user_id);
        }
    }

    public function test_publishes_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $action = new PublishAction;
        $result = $action($plan, $admin);

        $this->assertSame(PlanStatus::Published, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_already_published(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $action = new PublishAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }

    public function test_throws_when_archived(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $action = new PublishAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }
}
