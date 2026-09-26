<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use App\UseCases\MeetingPack\UnarchiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnarchiveActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unarchives_archived_meeting_pack(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->archived()->create();

        $result = (new UnarchiveAction)($plan, $admin);

        $this->assertSame(MeetingPackStatus::Draft, $result->status);
        $this->assertSame($admin->id, $result->updated_by_user_id);
    }

    public function test_throws_when_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $this->expectException(MeetingPackInvalidTransitionException::class);

        (new UnarchiveAction)($plan, $admin);
    }

    public function test_throws_when_published(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->published()->create();

        $this->expectException(MeetingPackInvalidTransitionException::class);

        (new UnarchiveAction)($plan, $admin);
    }
}
