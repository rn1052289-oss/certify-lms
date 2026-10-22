<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingGoogleEvent;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_calendar.client_id' => 'test-client', 'services.google_calendar.client_secret' => 'test-secret', 'services.google_calendar.redirect_uri' => 'http://localhost/settings/google-calendar/callback']);
        Http::preventStrayRequests();
    }

    public function test_only_coach_can_access_all_connection_routes(): void
    {
        foreach ([User::factory()->student()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('settings.google-calendar.redirect'))->assertForbidden();
            $this->get(route('settings.google-calendar.callback'))->assertForbidden();
            $this->delete(route('settings.google-calendar.destroy'))->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_connect_requests_offline_primary_access_and_ignores_external_return_path(): void
    {
        $this->freezeTime();
        $coach = User::factory()->coach()->create();
        $response = $this->actingAs($coach)->get(route('settings.google-calendar.redirect', ['redirect_path' => 'https://example.invalid/steal']));
        $response->assertRedirect();
        $url = parse_url($response->headers->get('Location'));
        $this->assertSame('accounts.google.com', $url['host']);
        parse_str($url['query'], $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('http://localhost/settings/google-calendar/callback', $query['redirect_uri']);
        $response->assertSessionHas('google_calendar_oauth', fn ($oauth) => $oauth['state'] === $query['state'] && $oauth['user_id'] === $coach->id && $oauth['expires_at'] === now()->addMinutes(10)->timestamp);
        Http::assertNothingSent();
    }

    public function test_callback_state_is_single_use_and_creates_primary_credential(): void
    {
        $coach = User::factory()->coach()->create();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-test', 'refresh_token' => 'refresh-test', 'expires_in' => 3600])]);
        $this->actingAs($coach)->withSession(['google_calendar_oauth' => $this->oauth($coach)])->get(route('settings.google-calendar.callback', ['state' => 'state-test', 'code' => 'code-test']))
            ->assertRedirect(route('settings.availability.index'))->assertSessionMissing('google_calendar_oauth');
        $this->assertDatabaseHas('google_credentials', ['user_id' => $coach->id, 'calendar_id' => 'primary', 'refresh_token' => 'refresh-test']);
        $this->get(route('settings.google-calendar.callback', ['state' => 'state-test', 'code' => 'code-test']))->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_invalid_expired_or_other_users_state_never_exchanges_code(): void
    {
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        $cases = [
            [$this->oauth($coach), 'wrong-state'],
            [$this->oauth($coach), 'state-test'],
            [$this->oauth($other), 'state-test'],
        ];
        $cases[1][0]['expires_at'] = now()->subSecond()->timestamp;
        foreach ($cases as [$oauth, $state]) {
            $this->actingAs($coach)->withSession(['google_calendar_oauth' => $oauth])->get(route('settings.google-calendar.callback', ['state' => $state, 'code' => 'code-test']))->assertForbidden()->assertSessionMissing('google_calendar_oauth');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_missing_refresh_token_does_not_replace_existing_connection(): void
    {
        $coach = User::factory()->coach()->create();
        $credential = $this->credential($coach);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600])]);
        $this->actingAs($coach)->withSession(['google_calendar_oauth' => $this->oauth($coach)])->get(route('settings.google-calendar.callback', ['state' => 'state-test', 'code' => 'code-test']))->assertSessionHas('error');
        $this->assertSame('old-access', $credential->fresh()->access_token);
        $this->assertDatabaseCount('google_credentials', 1);
    }

    public function test_disconnect_and_reconnect_do_not_delete_or_reassign_old_google_event(): void
    {
        $coach = User::factory()->coach()->create();
        $old = $this->credential($coach);
        $meeting = Meeting::factory()->forCoach($coach)->create();
        $mapping = MeetingGoogleEvent::create(['meeting_id' => $meeting->id, 'google_credential_id' => $old->id, 'calendar_id' => 'primary', 'event_id' => 'original-event']);
        $this->actingAs($coach)->delete(route('settings.google-calendar.destroy'))->assertRedirect(route('settings.availability.index'));
        $this->assertNull($mapping->fresh()->google_credential_id);
        Http::assertNothingSent();
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600])]);
        $this->withSession(['google_calendar_oauth' => $this->oauth($coach)])->get(route('settings.google-calendar.callback', ['state' => 'state-test', 'code' => 'code-test']))->assertSessionHas('success');
        $this->assertNotSame($old->id, GoogleCredential::where('user_id', $coach->id)->firstOrFail()->id);
        $this->assertNull($mapping->fresh()->google_credential_id);
        app(GoogleCalendarService::class)->cancel($meeting);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('meeting_google_events', ['id' => $mapping->id, 'event_id' => 'original-event']);
    }

    private function oauth(User $coach): array
    {
        return ['state' => 'state-test', 'user_id' => $coach->id, 'expires_at' => now()->addMinutes(10)->timestamp];
    }

    private function credential(User $coach): GoogleCredential
    {
        return GoogleCredential::create(['user_id' => $coach->id, 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary', 'connected_at' => now()]);
    }
}
