<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreTest extends TestCase
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
            'name' => 'スタンダードプラン',
            'description' => '標準的な受講プランです。',
            'duration_days' => 90,
            'default_meeting_quota' => 4,
            'sort_order' => 10,
        ], $override);
    }

    public function test_admin_can_create_plan_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload());

        $response->assertStatus(302);

        $this->assertDatabaseHas('plans', [
            'name' => 'スタンダードプラン',
            'duration_days' => 90,
            'default_meeting_quota' => 4,
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_create_plan_with_minimum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload([
                'name' => 'a',
                'description' => null,
                'duration_days' => 1,
                'default_meeting_quota' => 0,
                'sort_order' => 0,
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('plans', [
            'name' => 'a',
            'description' => null,
            'duration_days' => 1,
            'default_meeting_quota' => 0,
            'sort_order' => 0,
        ]);
    }

    public function test_admin_can_create_plan_with_maximum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload([
                'name' => str_repeat('a', 100),
                'description' => str_repeat('a', 2000),
                'duration_days' => 3650,
                'default_meeting_quota' => 1000,
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('plans', [
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
    public function test_admin_cannot_create_plan_with_out_of_range_value(
        array $invalidValue,
        string $errorField,
    ): void {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload($invalidValue));

        $response->assertStatus(302);
        $response->assertSessionHasErrors($errorField);

        $this->assertDatabaseCount('plans', 0);
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

    public function test_status_cannot_be_set_when_creating(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload(['status' => 'published']));

        $response->assertStatus(302);

        $plan = Plan::firstOrFail();

        $this->assertSame('draft', $plan->status->value);
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.plans.store'), $this->payload([
                'name' => '',
                'duration_days' => '',
                'default_meeting_quota' => '',
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasErrors([
            'name',
            'duration_days',
            'default_meeting_quota',
        ]);
    }

    public function test_coach_cannot_create_plan(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->post(route('admin.plans.store'), $this->payload());

        $response->assertStatus(403);
    }

    public function test_student_cannot_create_plan(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->post(route('admin.plans.store'), $this->payload());

        $response->assertStatus(403);
    }

    public function test_admin_can_view_create_form(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.create'));

        $response->assertStatus(200);
        $response->assertViewIs('plan.management.create');
        $response->assertSee('プランの新規作成');
        $response->assertSee('下書きとして保存');
    }
}
