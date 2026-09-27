<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_plan_index(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->create(['name' => 'テストプラン']);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index'));

        $response->assertStatus(200);
        $response->assertSee('テストプラン');
    }

    public function test_admin_can_filter_by_keyword(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->create(['name' => 'スタンダードプラン']);
        Plan::factory()->create(['name' => 'プレミアムコース']);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['keyword' => 'プラン']));

        $response->assertStatus(200);
        $response->assertSee('スタンダードプラン');
        $response->assertDontSee('プレミアムコース');
    }

    public function test_admin_can_filter_by_status(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->draft()->create(['name' => '下書きプラン']);
        Plan::factory()->published()->create(['name' => '公開中プラン']);
        Plan::factory()->archived()->create(['name' => 'アーカイブプラン']);

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index', ['status' => 'published']));

        $response->assertStatus(200);
        $response->assertSee('公開中プラン');
        $response->assertDontSee('下書きプラン');
        $response->assertDontSee('アーカイブプラン');
    }

    public function test_index_paginates_plans(): void
    {
        $admin = User::factory()->admin()->create();

        Plan::factory()->count(21)->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.plans.index'));

        $response->assertStatus(200);

        $response->assertViewHas('plans', function ($plans): bool {
            return $plans->total() === 21
                && $plans->perPage() === 20
                && $plans->count() === 20;
        });
    }
}
