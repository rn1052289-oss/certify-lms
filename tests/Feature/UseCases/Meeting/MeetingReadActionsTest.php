<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class MeetingReadActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_index_preserves_own_scope_filters_order_and_quota(): void
    {
        $this->freezeTime();
        $student = User::factory()->student()->create(['max_meetings' => 7]);
        $coach = User::factory()->coach()->create();
        $soon = Meeting::factory()->forStudent($student)->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDay()]);
        $later = Meeting::factory()->forStudent($student)->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDays(2)]);
        $completed = Meeting::factory()->forStudent($student)->forCoach($coach)->completed()->create(['scheduled_at' => now()->subDay()]);
        $canceled = Meeting::factory()->forStudent($student)->forCoach($coach)->canceled()->create(['scheduled_at' => now()->subDays(2)]);
        Meeting::factory()->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDays(3)]);

        foreach (['upcoming' => [$later->id, $soon->id], 'past' => [$completed->id, $canceled->id], 'all' => [$later->id, $soon->id, $completed->id, $canceled->id]] as $filter => $ids) {
            $result = app(IndexAction::class)($student, $filter);

            $this->assertSame($filter, $result['filter']);
            $this->assertSame($ids, array_column($result['meetings']->items(), 'id'));
            $this->assertSame(7, $result['meetingsRemaining']);
            $this->assertSame(20, $result['meetings']->perPage());
        }
    }

    public function test_student_index_paginates_twenty_meetings(): void
    {
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        for ($i = 1; $i <= 21; $i++) {
            Meeting::factory()->forEnrollment($enrollment)->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDays($i)->startOfHour()]);
        }

        $result = app(IndexAction::class)($student);

        $this->assertSame(21, $result['meetings']->total());
        $this->assertCount(20, $result['meetings']->items());
    }

    public function test_coach_index_preserves_scope_order_and_combined_student_enrollment_filters(): void
    {
        $this->freezeTime();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $firstEnrollment = Enrollment::factory()->for($student)->learning()->create();
        $secondEnrollment = Enrollment::factory()->for($student)->learning()->create();
        $soon = Meeting::factory()->forEnrollment($firstEnrollment)->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDay()]);
        $later = Meeting::factory()->forEnrollment($secondEnrollment)->forCoach($coach)->reserved()->create(['scheduled_at' => now()->addDays(2)]);
        $past = Meeting::factory()->forEnrollment($firstEnrollment)->forCoach($coach)->completed()->create(['scheduled_at' => now()->subDay()]);
        Meeting::factory()->forEnrollment($firstEnrollment)->reserved()->create(['scheduled_at' => now()->addDays(3)]);

        foreach (['upcoming' => [$soon->id, $later->id], 'past' => [$past->id], 'all' => [$later->id, $soon->id, $past->id]] as $filter => $ids) {
            $result = app(IndexAsCoachAction::class)($coach, $filter);

            $this->assertSame($ids, array_column($result['meetings']->items(), 'id'));
            $this->assertSame($filter, $result['filter']);
            $this->assertNull($result['studentFilter']);
            $this->assertNull($result['enrollmentFilter']);
            $this->assertSame(20, $result['meetings']->perPage());
        }
        $result = app(IndexAsCoachAction::class)($coach, 'all', $student->id, $secondEnrollment->id);
        $this->assertSame([$later->id], array_column($result['meetings']->items(), 'id'));
        $this->assertSame($student->id, $result['studentFilter']);
        $this->assertSame($secondEnrollment->id, $result['enrollmentFilter']);
        $result = app(IndexAsCoachAction::class)($coach, 'all', User::factory()->student()->create()->id, $firstEnrollment->id);
        $this->assertSame(0, $result['meetings']->total());
    }

    public function test_show_returns_meeting_with_detail_relations_and_deleted_cancel_actor(): void
    {
        $actor = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->create(['canceled_by_user_id' => $actor->id]);
        $memo = MeetingMemo::factory()->forMeeting($meeting)->create(['body' => '面談記録']);
        $actor->delete();

        $result = app(ShowAction::class)($meeting);

        $this->assertTrue($result->is($meeting));
        foreach (['enrollment', 'coach', 'student', 'canceledBy', 'meetingMemo'] as $relation) {
            $this->assertTrue($result->relationLoaded($relation));
        }
        $this->assertTrue($result->enrollment->relationLoaded('certification'));
        $this->assertSame($memo->id, $result->meetingMemo->id);
        $this->assertSame('面談記録', $result->meetingMemo->body);
        $this->assertSame($actor->id, $result->canceledBy->id);
    }

    public function test_availability_returns_iso_slots_and_calendar_warning(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create(['certification_id' => $certification->id, 'user_id' => $coach->id]);
        CoachAvailability::factory()->forCoach($coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($certification)->learning()->create();
        $date = now()->startOfDay()->next(Carbon::MONDAY);
        $this->partialMock(GoogleCalendarService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('busyForCoaches')->once()->andReturn([]);
            $mock->shouldReceive('warning')->once()->andReturn(GoogleCalendarService::WARNING);
        });

        $result = app(FetchAvailabilityAction::class)($enrollment, $date->toDateString());

        $this->assertSame([
            'date' => $date->toDateString(),
            'warning' => GoogleCalendarService::WARNING,
            'slots' => [
                ['slot_start' => $date->copy()->setTime(9, 0)->toIso8601String(), 'slot_end' => $date->copy()->setTime(10, 0)->toIso8601String(), 'available_coach_count' => 1],
                ['slot_start' => $date->copy()->setTime(10, 0)->toIso8601String(), 'slot_end' => $date->copy()->setTime(11, 0)->toIso8601String(), 'available_coach_count' => 1],
            ],
        ], $result);
    }

    public function test_availability_without_assigned_coaches_returns_empty_slots(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();
        $date = now()->addDay()->toDateString();

        $result = app(FetchAvailabilityAction::class)($enrollment, $date);

        $this->assertSame(['date' => $date, 'warning' => null, 'slots' => []], $result);
    }
}
