<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => '更新後のプラン',
            'description' => '更新後の説明です。',
            'duration_days' => 180,
            'default_meeting_quota' => 8,
            'sort_order' => 20,
        ], $override);
    }

    public function test_admin_can_update_plan(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->draft()->create([
            'name' => '更新前のプラン',
            'description' => '更新前の説明です。',
            'duration_days' => 90,
            'default_meeting_quota' => 4,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->put(
                route('admin.plans.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.plans.show', $plan));

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => '更新後のプラン',
            'description' => '更新後の説明です。',
            'duration_days' => 180,
            'default_meeting_quota' => 8,
            'sort_order' => 20,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_update_plan_with_minimum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload([
                'name' => 'a',
                'description' => null,
                'duration_days' => 1,
                'default_meeting_quota' => 0,
                'sort_order' => 0,
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => 'a',
            'description' => null,
            'duration_days' => 1,
            'default_meeting_quota' => 0,
            'sort_order' => 0,
        ]);
    }

    public function test_admin_can_update_plan_with_maximum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload([
                'name' => str_repeat('a', 100),
                'description' => str_repeat('a', 2000),
                'duration_days' => 3650,
                'default_meeting_quota' => 1000,
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'name' => str_repeat('a', 100),
            'description' => str_repeat('a', 2000),
            'duration_days' => 3650,
            'default_meeting_quota' => 1000,
        ]);
    }

    /**
     * @param array<string, mixed> $invalidValue
     */
    #[DataProvider('invalidValueProvider')]
    public function test_admin_cannot_update_plan_with_out_of_range_value(
        array $invalidValue,
        string $errorField,
    ): void {
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), $this->payload($invalidValue));

        $response->assertStatus(302);
        $response->assertSessionHasErrors($errorField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'name over maximum' => [['name' => str_repeat('a', 101)], 'name'],
            'description over maximum' => [['description' => str_repeat('a', 2001)], 'description'],
            'duration below minimum' => [['duration_days' => 0], 'duration_days'],
            'duration over maximum' => [['duration_days' => 3651], 'duration_days'],
            'meeting quota below minimum' => [['default_meeting_quota' => -1], 'default_meeting_quota'],
            'meeting quota over maximum' => [['default_meeting_quota' => 1001], 'default_meeting_quota'],
        ];
    }

    public function test_status_does_not_change_when_updating_basic_information(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->put(
                route('admin.plans.update', $plan),
                $this->payload(['status' => 'draft']),
            );

        $response->assertStatus(302);

        $plan->refresh();

        $this->assertSame('published', $plan->status->value);
    }

    public function test_created_by_user_does_not_change_when_updating(): void
    {
        $creator = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();

        $plan = Plan::factory()->draft()->create([
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        $response = $this->actingAs($editor)
            ->put(
                route('admin.plans.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(302);

        $plan->refresh();

        $this->assertSame($creator->id, $plan->created_by_user_id);
        $this->assertSame($editor->id, $plan->updated_by_user_id);
    }

    public function test_coach_cannot_update_plan(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($coach)
            ->put(
                route('admin.plans.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(403);
    }

    public function test_student_cannot_update_plan(): void
    {
        $student = User::factory()->student()->create();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($student)
            ->put(
                route('admin.plans.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(403);
    }

    public function test_admin_can_view_edit_form(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = Plan::factory()->draft()->create(['name' => '編集テスト用プラン']);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.edit', $plan));

        $response->assertStatus(200);
        $response->assertViewIs('plan.management.edit');
        $response->assertViewHas('plan');
        $response->assertSee('プランの編集');
        $response->assertSee('編集テスト用プラン');
        $response->assertSee('ステータス遷移は詳細画面から。');
    }
}
