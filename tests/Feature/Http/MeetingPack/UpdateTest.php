<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'name' => '更新後の面談パック',
            'description' => '更新後の説明です。',
            'meeting_count' => 10,
            'price' => 30000,
            'stripe_price_id' => 'price_updated',
            'sort_order' => 20,
        ], $override);
    }

    public function test_admin_can_update_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = MeetingPack::factory()->draft()->create([
            'name' => '更新前の面談パック',
            'description' => '更新前の説明です。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => 'price_before',
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $plan->id,
            'name' => '更新後の面談パック',
            'description' => '更新後の説明です。',
            'meeting_count' => 10,
            'price' => 30000,
            'stripe_price_id' => 'price_updated',
            'sort_order' => 20,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_does_not_change_when_updating_basic_information(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->patch(
                route('admin.meeting-packs.update', $plan),
                $this->payload([
                    'status' => 'draft',
                ]),
            );

        $response->assertStatus(302);

        $plan->refresh();

        $this->assertSame('published', $plan->status->value);
    }

    public function test_created_by_user_does_not_change_when_updating(): void
    {
        $creator = User::factory()->admin()->create();
        $editor = User::factory()->admin()->create();

        $plan = MeetingPack::factory()->draft()->create([
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);

        $response = $this->actingAs($editor)
            ->patch(
                route('admin.meeting-packs.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(302);

        $plan->refresh();

        $this->assertSame($creator->id, $plan->created_by_user_id);
        $this->assertSame($editor->id, $plan->updated_by_user_id);
    }

    public function test_coach_cannot_update_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($coach)
            ->patch(
                route('admin.meeting-packs.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(403);
    }

    public function test_student_cannot_update_meeting_pack(): void
    {
        $student = User::factory()->student()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($student)
            ->patch(
                route('admin.meeting-packs.update', $plan),
                $this->payload(),
            );

        $response->assertStatus(403);
    }

    public function test_admin_can_view_edit_form(): void
    {
        $admin = User::factory()->admin()->create();

        $plan = MeetingPack::factory()->draft()->create([
            'name' => '編集テスト用パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.edit', $plan));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.edit');
        $response->assertViewHas('plan');
        $response->assertSee('編集テスト用パック の編集');
        $response->assertSee('ステータス遷移は詳細画面のアクションから操作します。');
    }
}
