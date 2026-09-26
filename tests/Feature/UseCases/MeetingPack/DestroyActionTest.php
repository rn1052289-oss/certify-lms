<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MeetingPack;

use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use App\UseCases\MeetingPack\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_draft_meeting_pack(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        (new DestroyAction)($plan);

        $this->assertDatabaseMissing('meeting_packs', [
            'id' => $plan->id,
        ]);
    }

    public function test_deletes_archived_meeting_pack(): void
    {
        $plan = MeetingPack::factory()->archived()->create();

        (new DestroyAction)($plan);

        $this->assertDatabaseMissing('meeting_packs', [
            'id' => $plan->id,
        ]);
    }

    public function test_throws_when_published(): void
    {
        $plan = MeetingPack::factory()->published()->create();

        $this->expectException(MeetingPackNotDeletableException::class);

        (new DestroyAction)($plan);
    }
}
