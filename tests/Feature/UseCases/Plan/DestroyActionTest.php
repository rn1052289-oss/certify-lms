<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Plan;

use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use App\UseCases\Plan\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_draft_plan_without_references(): void
    {
        $plan = Plan::factory()->draft()->create();

        $action = new DestroyAction;
        $action($plan);

        $this->assertDatabaseMissing('plans', [
            'id' => $plan->id,
        ]);
    }

    public function test_throws_when_draft_plan_has_user_reference(): void
    {
        $plan = Plan::factory()->draft()->create();

        User::factory()->student()->create([
            'plan_id' => $plan->id,
        ]);

        $action = new DestroyAction;

        $this->expectException(PlanNotDeletableException::class);

        $action($plan);
    }

    public function test_throws_when_draft_plan_has_user_plan_log_reference(): void
    {
        $plan = Plan::factory()->draft()->create();

        UserPlanLog::factory()->create([
            'plan_id' => $plan->id,
        ]);

        $action = new DestroyAction;

        $this->expectException(PlanNotDeletableException::class);

        $action($plan);
    }

    public function test_throws_when_published(): void
    {
        $plan = Plan::factory()->published()->create();

        $action = new DestroyAction;

        $this->expectException(PlanNotDeletableException::class);

        $action($plan);
    }

    public function test_throws_when_archived(): void
    {
        $plan = Plan::factory()->archived()->create();

        $action = new DestroyAction;

        $this->expectException(PlanNotDeletableException::class);

        $action($plan);
    }
}
