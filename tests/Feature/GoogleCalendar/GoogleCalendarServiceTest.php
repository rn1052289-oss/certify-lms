<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Enums\MeetingStatus;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingGoogleEvent;
use App\Models\User;
use App\Services\GoogleCalendarClient;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.google_calendar.client_id' => 'test-client', 'services.google_calendar.client_secret' => 'test-secret', 'services.google_calendar.redirect_uri' => 'http://localhost/settings/google-calendar/callback']);
    }

    public function test_expired_access_token_is_refreshed_before_free_busy_and_refresh_is_preserved(): void
    {
        $credential = $this->credential();
        $credential->update(['expires_at' => now()->subMinute()]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed-access', 'expires_in' => 3600]),
            'https://www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => []]]]),
        ]);
        $this->assertSame([], app(GoogleCalendarClient::class)->busy($credential, now(), now()->addHour()));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['refresh_token'] === 'refresh-test' && $request['grant_type'] === 'refresh_token');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/freeBusy') && $request->hasHeader('Authorization', 'Bearer renewed-access'));
        $this->assertSame('refresh-test', $credential->fresh()->refresh_token);
        $this->assertSame('renewed-access', $credential->fresh()->access_token);
        Http::assertSentCount(2);
    }

    public function test_refresh_failure_falls_back_with_warning(): void
    {
        $credential = $this->credential();
        $credential->update(['expires_at' => now()->subMinute()]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $service = app(GoogleCalendarService::class);
        $this->assertSame([$credential->user_id => []], $service->busyForCoaches(collect([$credential->user]), now(), now()->addHour()));
        $this->assertSame(GoogleCalendarService::WARNING, $service->warning());
        Http::assertSentCount(1);
    }

    public function test_unconnected_coach_makes_no_request(): void
    {
        $coach = User::factory()->coach()->create();
        $service = app(GoogleCalendarService::class);
        $this->assertSame([], $service->busyForCoaches(collect([$coach]), now(), now()->addHour()));
        $this->assertNull($service->warning());
        Http::assertNothingSent();
    }

    public function test_partial_overlap_and_utc_dates_are_compared_as_instants_with_half_open_boundaries(): void
    {
        $service = app(GoogleCalendarService::class);
        $start = Carbon::parse('2026-10-19 09:00:00', 'Asia/Tokyo');
        $end = $start->copy()->addHour();
        $this->assertTrue($service->overlaps([['start' => '2026-10-19T00:30:00Z', 'end' => '2026-10-19T01:30:00Z']], $start, $end));
        $this->assertFalse($service->overlaps([['start' => '2026-10-18T23:00:00Z', 'end' => '2026-10-19T00:00:00Z']], $start, $end));
        $this->assertFalse($service->overlaps([['start' => '2026-10-19T01:00:00Z', 'end' => '2026-10-19T02:00:00Z']], $start, $end));
        $this->assertTrue($service->overlaps([['start' => '2026-10-18T15:00:00Z', 'end' => '2026-10-19T15:00:00Z']], $start, $end));
        Http::assertNothingSent();
    }

    public function test_event_uses_booking_url_snapshot_and_cancellation_removes_only_its_mapping(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->reserved()->forCoach($credential->user)->create(['meeting_url_snapshot' => 'https://example.invalid/original']);
        $credential->user->update(['meeting_url' => 'https://example.invalid/changed']);
        Http::fake(function (Request $request) {
            return $request->method() === 'DELETE' ? Http::response('', 204) : Http::response(['id' => $request['id']]);
        });
        $service = app(GoogleCalendarService::class);
        $service->reserve($meeting);
        $this->assertDatabaseHas('meeting_google_events', ['meeting_id' => $meeting->id, 'google_credential_id' => $credential->id, 'event_id' => hash('sha256', $meeting->id)]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['location'] === 'https://example.invalid/original' && $request['description'] === 'https://example.invalid/original');
        $meeting->update(['status' => MeetingStatus::Canceled]);
        $service->cancel($meeting);
        $this->assertDatabaseMissing('meeting_google_events', ['meeting_id' => $meeting->id]);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/'.hash('sha256', $meeting->id)));
        Http::assertSentCount(2);
    }

    public function test_cancellation_during_google_creation_cleans_up_created_event(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->reserved()->forCoach($credential->user)->create();
        Http::fake(function (Request $request) use ($meeting) {
            if ($request->method() === 'POST') {
                $meeting->update(['status' => MeetingStatus::Canceled]);

                return Http::response(['id' => $request['id']]);
            }

            return Http::response('', 204);
        });
        $service = app(GoogleCalendarService::class);
        $service->reserve($meeting);
        $this->assertDatabaseMissing('meeting_google_events', ['meeting_id' => $meeting->id]);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_cancellation_during_creation_keeps_mapping_when_google_deletion_fails(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->reserved()->forCoach($credential->user)->create();
        Http::fake(function (Request $request) use ($meeting) {
            if ($request->method() === 'POST') {
                $meeting->update(['status' => MeetingStatus::Canceled]);

                return Http::response(['id' => $request['id']]);
            }

            return Http::response([], 503);
        });
        $service = app(GoogleCalendarService::class);
        $service->reserve($meeting);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame(GoogleCalendarService::WARNING, $service->warning());
        $this->assertDatabaseHas('meeting_google_events', ['meeting_id' => $meeting->id, 'event_id' => hash('sha256', $meeting->id)]);
        Http::assertSentCount(2);
    }

    public function test_already_deleted_event_is_successful_cancellation(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->forCoach($credential->user)->create();
        MeetingGoogleEvent::create(['meeting_id' => $meeting->id, 'google_credential_id' => $credential->id, 'calendar_id' => 'primary', 'event_id' => 'deleted-event']);
        Http::fake(['*' => Http::response('', 410)]);
        $service = app(GoogleCalendarService::class);
        $service->cancel($meeting);
        $this->assertNull($service->warning());
        $this->assertDatabaseMissing('meeting_google_events', ['meeting_id' => $meeting->id]);
    }

    public function test_google_creation_failure_keeps_reserved_meeting_without_false_mapping(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->reserved()->forCoach($credential->user)->create();
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $service = app(GoogleCalendarService::class);
        $service->reserve($meeting);
        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame(GoogleCalendarService::WARNING, $service->warning());
        $this->assertDatabaseMissing('meeting_google_events', ['meeting_id' => $meeting->id]);
    }

    public function test_malformed_busy_period_falls_back_without_hiding_the_coach(): void
    {
        $credential = $this->credential();
        Http::fake(['*' => Http::response(['calendars' => ['primary' => ['busy' => [['start' => 'invalid-date', 'end' => null]]]]])]);
        $service = app(GoogleCalendarService::class);
        $this->assertSame([$credential->user_id => []], $service->busyForCoaches(collect([$credential->user]), now(), now()->addHour()));
        $this->assertSame(GoogleCalendarService::WARNING, $service->warning());
    }

    public function test_failed_deletion_keeps_mapping_and_canceled_status_for_diagnosis(): void
    {
        $credential = $this->credential();
        $meeting = Meeting::factory()->canceled()->forCoach($credential->user)->create();
        $mapping = MeetingGoogleEvent::create(['meeting_id' => $meeting->id, 'google_credential_id' => $credential->id, 'calendar_id' => 'primary', 'event_id' => 'existing-event']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $service = app(GoogleCalendarService::class);
        $service->cancel($meeting);
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_google_events', ['id' => $mapping->id]);
        $this->assertSame(GoogleCalendarService::WARNING, $service->warning());
    }

    private function credential(): GoogleCredential
    {
        return GoogleCredential::create(['user_id' => User::factory()->coach()->create()->id, 'access_token' => 'access-test', 'refresh_token' => 'refresh-test', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary', 'connected_at' => now()]);
    }
}
