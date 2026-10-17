<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnarchiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_transition_when_latest_status_is_invalid(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();
        $updatedBy = $plan->updated_by_user_id;
        Plan::query()->whereKey($plan->id)->update(['status' => PlanStatus::Published->value]);

        try {
            (new UnarchiveAction)($plan, $admin);
            $this->fail('最新状態が条件を満たさない遷移は拒否します。');
        } catch (PlanInvalidTransitionException $exception) {
            $this->assertSame(PlanStatus::Published, $plan->fresh()->status);
            $this->assertSame($updatedBy, $plan->fresh()->updated_by_user_id);
        }
    }

    public function test_unarchives_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $action = new UnarchiveAction;
        $result = $action($plan, $admin);

        $this->assertSame(PlanStatus::Draft, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $action = new UnarchiveAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }

    public function test_throws_when_published(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $action = new UnarchiveAction;

        $this->expectException(PlanInvalidTransitionException::class);

        $action($plan, $admin);
    }
}
