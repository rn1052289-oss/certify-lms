<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\GoogleCalendarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('external-api')]
class GoogleCalendarClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['services.google_calendar.client_id' => 'test-client', 'services.google_calendar.client_secret' => 'test-secret', 'services.google_calendar.redirect_uri' => 'http://localhost/settings/google-calendar/callback']);
    }

    public function test_code_exchange_sends_form_parameters_and_returns_token(): void
    {
        $token = ['access_token' => 'access-test', 'refresh_token' => 'refresh-test', 'expires_in' => 3600];
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($token)]);
        $this->assertSame($token, app(GoogleCalendarClient::class)->exchangeCode('code-test'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request->data() === ['code' => 'code-test', 'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.google_calendar.redirect_uri'), 'client_id' => 'test-client', 'client_secret' => 'test-secret']);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_exchange_is_rejected_without_exposing_body(array $body, int $status): void
    {
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response($body, $status)]);
        try {
            app(GoogleCalendarClient::class)->exchangeCode('code-test');
            $this->fail('不正なトークン応答が受け入れられました。');
        } catch (RuntimeException $exception) {
            $this->assertSame('Google OAuth token request failed.', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('google_credentials', 0);
    }

    public static function invalidTokens(): array
    {
        return [
            'missing access token' => [['expires_in' => 3600], 200],
            'expired immediately' => [['access_token' => 'access-test', 'expires_in' => 0], 200],
            'invalid grant' => [['error' => 'private-upstream-detail'], 400],
            'server failure' => [['error' => 'private-upstream-detail'], 503],
        ];
    }

    public function test_refresh_rotates_tokens_before_operation_and_is_not_repeated_while_valid(): void
    {
        $credential = $this->credential();
        $credential->update(['expires_at' => now()->addMinute()]);
        $calls = [];
        Http::fake([
            'https://oauth2.googleapis.com/token' => function (Request $request) use (&$calls) {
                $calls[] = 'refresh';
                $this->assertSame('refresh-test', $request['refresh_token']);
                $this->assertSame('refresh_token', $request['grant_type']);

                return Http::response(['access_token' => 'renewed-access', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600]);
            },
            'https://www.googleapis.com/calendar/v3/freeBusy' => function (Request $request) use (&$calls) {
                $calls[] = 'busy';
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer renewed-access'));
                $this->assertSame([['id' => 'primary']], $request['items']);
                $this->assertSame(now()->toRfc3339String(), $request['timeMin']);
                $this->assertSame(now()->addHour()->toRfc3339String(), $request['timeMax']);

                return Http::response(['calendars' => ['primary' => ['busy' => []]]]);
            },
        ]);
        $client = app(GoogleCalendarClient::class);
        $this->assertSame([], $client->busy($credential, now(), now()->addHour()));
        $this->assertSame([], $client->busy($credential, now(), now()->addHour()));
        $this->assertSame(['refresh', 'busy', 'busy'], $calls);
        $this->assertSame('renewed-refresh', $credential->fresh()->refresh_token);
        $this->assertSame(now()->addHour()->timestamp, $credential->fresh()->expires_at->timestamp);
        Http::assertSentCount(3);
    }

    #[DataProvider('invalidBusyResponses')]
    public function test_failed_or_malformed_busy_response_is_rejected(array $body, int $status): void
    {
        $credential = $this->credential();
        Http::fake(['https://www.googleapis.com/calendar/v3/freeBusy' => Http::response($body, $status)]);
        $this->expectExceptionMessage('Google freeBusy request failed.');
        app(GoogleCalendarClient::class)->busy($credential, now(), now()->addHour());
    }

    public static function invalidBusyResponses(): array
    {
        return [
            'unavailable' => [[], 503],
            'missing calendar' => [['calendars' => []], 200],
            'missing busy' => [['calendars' => ['primary' => []]], 200],
            'calendar error' => [['calendars' => ['primary' => ['errors' => [['reason' => 'notFound']], 'busy' => []]]], 200],
        ];
    }

    #[DataProvider('eventCreationResponses')]
    public function test_event_creation_requires_the_requested_id(array $body, bool $success): void
    {
        $credential = $this->credential();
        $credential->update(['calendar_id' => 'team/calendar@example.test']);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/team%2Fcalendar%40example.test/events';
        $event = ['id' => 'event-test', 'summary' => '面談'];
        Http::fake([$url => Http::response($body)]);
        if (! $success) {
            $this->expectExceptionMessage('Google event creation failed.');
        }
        $this->assertSame('event-test', app(GoogleCalendarClient::class)->createEvent($credential, $event));
        Http::assertSent(fn (Request $request) => $request->url() === $url && $request->data() === $event);
        Http::assertSentCount(1);
    }

    public static function eventCreationResponses(): array
    {
        return ['matching' => [['id' => 'event-test'], true], 'empty' => [[], false], 'different id' => [['id' => 'different'], false]];
    }

    #[DataProvider('deletionStatuses')]
    public function test_already_deleted_events_are_accepted_and_other_failures_rejected(int $status, bool $success): void
    {
        $credential = $this->credential();
        $url = 'https://www.googleapis.com/calendar/v3/calendars/old%2Fcalendar%40example.test/events/old%2Fevent';
        Http::fake([$url => Http::response('', $status)]);
        if (! $success) {
            $this->expectExceptionMessage('Google event deletion failed.');
        }
        app(GoogleCalendarClient::class)->deleteEvent($credential, 'old/calendar@example.test', 'old/event');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === $url);
        Http::assertSentCount(1);
    }

    public static function deletionStatuses(): array
    {
        return ['deleted' => [204, true], 'not found' => [404, true], 'gone' => [410, true], 'forbidden' => [403, false], 'unavailable' => [503, false]];
    }

    private function credential(): GoogleCredential
    {
        return GoogleCredential::create(['user_id' => User::factory()->coach()->create()->id, 'access_token' => 'access-test', 'refresh_token' => 'refresh-test', 'expires_at' => now()->addHour(), 'calendar_id' => 'primary', 'connected_at' => now()]);
    }
}
