<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_published_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.archive', $plan));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.plans.show', $plan));

        $plan->refresh();

        $this->assertSame('archived', $plan->status->value);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_cannot_archive_draft_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.plans.archive', $plan));

        $response->assertStatus(409);

        $this->assertSame('draft', $plan->fresh()->status->value);
    }

    public function test_admin_cannot_archive_archived_plan(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->archived()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.plans.archive', $plan));

        $response->assertStatus(409);

        $this->assertSame('archived', $plan->fresh()->status->value);
    }

    public function test_coach_cannot_archive_plan(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($coach)
            ->post(route('admin.plans.archive', $plan));

        $response->assertStatus(403);

        $this->assertSame('published', $plan->fresh()->status->value);
    }

    public function test_student_cannot_archive_plan(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($student)
            ->post(route('admin.plans.archive', $plan));

        $response->assertStatus(403);

        $this->assertSame('published', $plan->fresh()->status->value);
    }
}
