<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleCalendarBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::preventStrayRequests();
    }

    public function test_partial_google_busy_is_excluded_from_student_availability(): void
    {
        [$student, $coach, $enrollment, $at] = $this->booking();
        Http::fake(['*' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => $at->copy()->addMinutes(30)->toRfc3339String(), 'end' => $at->copy()->addMinutes(90)->toRfc3339String()]]]]])]);
        $this->actingAs($student)->getJson(route('meetings.availability', ['enrollment' => $enrollment, 'date' => $at->toDateString()]))->assertOk()->assertJsonCount(0, 'slots')->assertJsonPath('warning', null);
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_reservation_post_rechecks_busy_after_an_earlier_available_display(): void
    {
        [$student, $coach, $enrollment, $at] = $this->booking();
        Http::fakeSequence()->push(['calendars' => ['primary' => ['busy' => []]]])->push(['calendars' => ['primary' => ['busy' => [['start' => $at->toRfc3339String(), 'end' => $at->copy()->addHour()->toRfc3339String()]]]]]);
        $this->actingAs($student)->getJson(route('meetings.availability', ['enrollment' => $enrollment, 'date' => $at->toDateString()]))->assertOk()->assertJsonCount(1, 'slots');
        app()->forgetScopedInstances();
        $response = $this->postJson(route('meetings.store', $enrollment), ['scheduled_at' => $at->format('Y-m-d\TH:i:s'), 'topic' => '相談']);
        $response->assertStatus(422);
        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    public function test_google_creation_happens_after_booking_transaction_and_quota_commit(): void
    {
        [$student, $coach, $enrollment, $at] = $this->booking();
        $baseline = DB::transactionLevel();
        $creationObserved = false;
        Http::fake(function (Request $request) use ($baseline, $student, &$creationObserved) {
            if (str_ends_with($request->url(), '/freeBusy')) {
                return Http::response(['calendars' => ['primary' => ['busy' => []]]]);
            }
            $creationObserved = true;
            // RefreshDatabase が開いた外側のtransaction以外は完了している。
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertDatabaseHas('meetings', ['student_id' => $student->id, 'status' => MeetingStatus::Reserved->value]);
            $this->assertDatabaseHas('meeting_quota_transactions', ['user_id' => $student->id, 'amount' => -1]);

            return Http::response(['id' => $request['id']]);
        });
        $this->actingAs($student)->post(route('meetings.store', $enrollment), ['scheduled_at' => $at->format('Y-m-d\TH:i:s'), 'topic' => '相談'])->assertRedirect();
        $this->assertTrue($creationObserved);
        $this->assertDatabaseCount('meeting_google_events', 1);
    }

    public function test_google_failure_keeps_availability_booking_and_cancellation_working_with_warning(): void
    {
        [$student, $coach, $enrollment, $at] = $this->booking();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->actingAs($student)->getJson(route('meetings.availability', ['enrollment' => $enrollment, 'date' => $at->toDateString()]))->assertOk()->assertJsonCount(1, 'slots')->assertJsonPath('warning', GoogleCalendarService::WARNING);
        app()->forgetScopedInstances();
        $this->post(route('meetings.store', $enrollment), ['scheduled_at' => $at->format('Y-m-d\TH:i:s'), 'topic' => '相談'])->assertRedirect()->assertSessionHas('warning', GoogleCalendarService::WARNING);
        $meeting = Meeting::where('student_id', $student->id)->firstOrFail();
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        app()->forgetScopedInstances();
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'amount' => 1]);
    }

    private function booking(): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://example.invalid/meeting']);
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, ['id' => (string) Str::ulid(), 'assigned_by_user_id' => $admin->id, 'assigned_at' => now(), 'unassigned_at' => null]);
        CoachAvailability::factory()->forCoach($coach)->monday()->timeRange('10:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        GoogleCredential::create(['user_id' => $coach->id, 'access_token' => 'access-test', 'refresh_token' => 'refresh-test', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary', 'connected_at' => now()]);
        $at = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        return [$student, $coach, $enrollment, $at];
    }
}
