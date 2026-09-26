<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_perform_all_meeting_pack_operations(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->view($admin, $plan));
        $this->assertTrue($policy->create($admin));
        $this->assertTrue($policy->update($admin, $plan));
        $this->assertTrue($policy->delete($admin, $plan));
        $this->assertTrue($policy->publish($admin, $plan));
        $this->assertTrue($policy->archive($admin, $plan));
        $this->assertTrue($policy->unarchive($admin, $plan));
    }

    public function test_coach_cannot_perform_meeting_pack_operations(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertFalse($policy->viewAny($coach));
        $this->assertFalse($policy->view($coach, $plan));
        $this->assertFalse($policy->create($coach));
        $this->assertFalse($policy->update($coach, $plan));
        $this->assertFalse($policy->delete($coach, $plan));
        $this->assertFalse($policy->publish($coach, $plan));
        $this->assertFalse($policy->archive($coach, $plan));
        $this->assertFalse($policy->unarchive($coach, $plan));
    }

    public function test_student_cannot_perform_meeting_pack_operations(): void
    {
        $student = User::factory()->student()->create();
        $plan = MeetingPack::factory()->draft()->create();
        $policy = new MeetingPackPolicy;

        $this->assertFalse($policy->viewAny($student));
        $this->assertFalse($policy->view($student, $plan));
        $this->assertFalse($policy->create($student));
        $this->assertFalse($policy->update($student, $plan));
        $this->assertFalse($policy->delete($student, $plan));
        $this->assertFalse($policy->publish($student, $plan));
        $this->assertFalse($policy->archive($student, $plan));
        $this->assertFalse($policy->unarchive($student, $plan));
    }
}
