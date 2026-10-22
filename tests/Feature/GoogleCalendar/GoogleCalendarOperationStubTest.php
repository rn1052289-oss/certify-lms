<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarClient;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GoogleCalendarOperationStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_flow_uses_calendar_operations_without_http_details(): void
    {
        $coach = User::factory()->coach()->create();
        $client = $this->mock(GoogleCalendarClient::class);
        $client->shouldReceive('authorizationUrl')->once()->withArgs(fn ($state) => is_string($state) && strlen($state) > 20)
            ->andReturn('https://accounts.google.com/o/oauth2/v2/auth?state=test');
        $client->shouldReceive('exchangeCode')->once()->with('authorization-code')
            ->andReturn(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 3600]);
        $this->actingAs($coach)->get(route('settings.google-calendar.redirect'))->assertRedirect('https://accounts.google.com/o/oauth2/v2/auth?state=test');
        $state = session('google_calendar_oauth.state');
        $this->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'authorization-code']))
            ->assertRedirect(route('settings.availability.index'))->assertSessionHas('success');
        $this->assertDatabaseHas('google_credentials', ['user_id' => $coach->id, 'calendar_id' => 'primary']);
        $this->delete(route('settings.google-calendar.destroy'))->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('google_credentials', ['user_id' => $coach->id]);
        Http::assertNothingSent();
    }

    public function test_booking_and_cancellation_use_calendar_service_and_preserve_quota(): void
    {
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://example.invalid/meeting']);
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, ['id' => (string) Str::ulid(), 'assigned_by_user_id' => $admin->id, 'assigned_at' => now(), 'unassigned_at' => null]);
        CoachAvailability::factory()->forCoach($coach)->monday()->timeRange('10:00:00', '11:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $at = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
        $calendar = $this->partialMock(GoogleCalendarService::class);
        $calendar->shouldReceive('busyForCoaches')->andReturn([$coach->id => []]);
        $calendar->shouldReceive('reserve')->once()->withArgs(function (Meeting $meeting) use ($coach, $student) {
            $this->assertSame($coach->id, $meeting->coach_id);
            $this->assertSame(MeetingStatus::Reserved, $meeting->status);
            $this->assertDatabaseHas('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'user_id' => $student->id, 'amount' => -1]);

            return true;
        });
        $calendar->shouldReceive('cancel')->once()->withArgs(function (Meeting $meeting) use ($student) {
            $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
            $this->assertDatabaseHas('meeting_quota_transactions', ['related_meeting_id' => $meeting->id, 'user_id' => $student->id, 'amount' => 1]);

            return true;
        });
        $this->actingAs($student)->getJson(route('meetings.availability', ['enrollment' => $enrollment, 'date' => $at->toDateString()]))
            ->assertOk()->assertJsonCount(1, 'slots');
        $this->post(route('meetings.store', $enrollment), ['scheduled_at' => $at->format('Y-m-d\TH:i:s'), 'topic' => '相談'])->assertRedirect();
        $meeting = Meeting::where('student_id', $student->id)->sole();
        $this->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame(0, (int) $student->meetingQuotaTransactions()->sum('amount'));
        Http::assertNothingSent();
    }
}
