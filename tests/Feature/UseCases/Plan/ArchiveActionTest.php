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
