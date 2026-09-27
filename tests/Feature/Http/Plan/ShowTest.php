<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->draft()->create(['name' => '詳細表示テスト用プラン']);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.show', $plan));

        $response->assertStatus(200);
        $response->assertViewIs('plan.management.show');
        $response->assertViewHas('plan');
        $response->assertSee('詳細表示テスト用プラン');
    }

    public function test_coach_cannot_view_plan(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($coach)
            ->get(route('admin.plans.show', $plan));

        $response->assertStatus(403);
    }

    public function test_student_cannot_view_plan(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($student)
            ->get(route('admin.plans.show', $plan));

        $response->assertStatus(403);
    }
}
