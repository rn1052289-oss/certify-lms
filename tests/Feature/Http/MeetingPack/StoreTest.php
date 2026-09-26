<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'name' => '5回面談パック',
            'description' => '面談を5回追加できるパックです。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => 'price_test',
            'sort_order' => 10,
        ], $override);
    }

    public function test_admin_can_create_meeting_pack_as_draft(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertStatus(302);

        $this->assertDatabaseHas('meeting_packs', [
            'name' => '5回面談パック',
            'meeting_count' => 5,
            'price' => 15000,
            'status' => 'draft',
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_status_cannot_be_set_when_creating(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload([
                'status' => 'published',
            ]));

        $response->assertStatus(302);

        $plan = MeetingPack::firstOrFail();

        $this->assertSame('draft', $plan->status->value);
    }

    public function test_required_fields_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.store'), $this->payload([
                'name' => '',
                'meeting_count' => '',
                'price' => '',
            ]));

        $response->assertStatus(302);
        $response->assertSessionHasErrors([
            'name',
            'meeting_count',
            'price',
        ]);
    }

    public function test_coach_cannot_create_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertStatus(403);
    }

    public function test_student_cannot_create_meeting_pack(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->post(route('admin.meeting-packs.store'), $this->payload());

        $response->assertStatus(403);
    }

    public function test_admin_can_view_create_form(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.create'));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.create');
        $response->assertSee('面談パックの新規作成');
        $response->assertSee('下書きとして保存');
    }
}
