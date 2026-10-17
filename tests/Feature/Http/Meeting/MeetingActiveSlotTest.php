<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingActiveSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_meeting_also_blocks_the_same_slot(): void
    {
        $meeting = Meeting::factory()->completed()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Meeting::factory()->reserved()->forCoach($meeting->coach)->create([
            'scheduled_at' => $meeting->scheduled_at,
        ]);
    }

    public function test_multiple_canceled_histories_allow_a_new_reservation(): void
    {
        $meeting = Meeting::factory()->canceled()->create();
        Meeting::factory()->canceled()->forCoach($meeting->coach)->create([
            'scheduled_at' => $meeting->scheduled_at,
        ]);
        Meeting::factory()->reserved()->forCoach($meeting->coach)->create([
            'scheduled_at' => $meeting->scheduled_at,
        ]);

        $this->assertDatabaseCount('meetings', 3);
    }

    public function test_different_coaches_can_reserve_the_same_time(): void
    {
        $meeting = Meeting::factory()->reserved()->create();
        $otherCoach = User::factory()->coach()->create();
        Meeting::factory()->reserved()->forCoach($otherCoach)->create([
            'scheduled_at' => $meeting->scheduled_at,
        ]);

        $this->assertDatabaseCount('meetings', 2);
    }
}
