<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_published_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($admin)
            ->post(route('admin.meeting-packs.archive', $plan));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $plan->refresh();

        $this->assertSame('archived', $plan->status->value);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_admin_cannot_archive_draft_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.meeting-packs.archive', $plan));

        $response->assertStatus(409);

        $this->assertSame('draft', $plan->fresh()->status->value);
    }

    public function test_admin_cannot_archive_archived_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.meeting-packs.archive', $plan));

        $response->assertStatus(409);

        $this->assertSame('archived', $plan->fresh()->status->value);
    }

    public function test_coach_cannot_archive_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($coach)
            ->post(route('admin.meeting-packs.archive', $plan));

        $response->assertStatus(403);

        $this->assertSame('published', $plan->fresh()->status->value);
    }

    public function test_student_cannot_archive_meeting_pack(): void
    {
        $student = User::factory()->student()->create();
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($student)
            ->post(route('admin.meeting-packs.archive', $plan));

        $response->assertStatus(403);

        $this->assertSame('published', $plan->fresh()->status->value);
    }
}
