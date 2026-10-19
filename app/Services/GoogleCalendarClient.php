<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCredential;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OAuth と Calendar REST API の通信境界。外部レスポンスや秘密を例外文へ含めない。 */
class GoogleCalendarClient
{
    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        return $this->token(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => config('services.google_calendar.redirect_uri')]);
    }

    private function token(array $parameters): array
    {
        $this->ensureConfigured();
        $response = Http::asForm()->timeout(5)->connectTimeout(3)->post('https://oauth2.googleapis.com/token', $parameters + [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
        ]);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null) || ($data['expires_in'] ?? 0) <= 0) {
            throw new RuntimeException('Google OAuth token request failed.');
        }

        return $data;
    }

    private function authorized(GoogleCredential $credential): PendingRequest
    {
        if ($credential->expires_at->lessThanOrEqualTo(now()->addMinute())) {
            $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $credential->refresh_token]);
            $credential->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $credential->refresh_token,
                'expires_at' => now()->addSeconds((int) $token['expires_in']),
            ]);
        }

        return Http::withToken($credential->access_token)->acceptJson()->timeout(5)->connectTimeout(3);
    }

    public function busy(GoogleCredential $credential, CarbonInterface $start, CarbonInterface $end): array
    {
        $response = $this->authorized($credential)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $start->toRfc3339String(),
            'timeMax' => $end->toRfc3339String(),
            'items' => [['id' => $credential->calendar_id]],
        ]);
        $calendar = $response->json('calendars.'.$credential->calendar_id);
        if (! $response->successful() || ! is_array($calendar) || ! empty($calendar['errors']) || ! is_array($calendar['busy'] ?? null)) {
            throw new RuntimeException('Google freeBusy request failed.');
        }

        return $calendar['busy'];
    }

    public function createEvent(GoogleCredential $credential, array $event): string
    {
        $response = $this->authorized($credential)->post($this->eventsUrl($credential->calendar_id), $event);
        if (! $response->successful() || ! is_string($response->json('id')) || $response->json('id') !== $event['id']) {
            throw new RuntimeException('Google event creation failed.');
        }

        return $response->json('id');
    }

    public function deleteEvent(GoogleCredential $credential, string $calendarId, string $eventId): void
    {
        $response = $this->authorized($credential)->delete($this->eventsUrl($calendarId).'/'.rawurlencode($eventId));
        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Google event deletion failed.');
        }
    }

    private function eventsUrl(string $calendarId): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
    }

    private function ensureConfigured(): void
    {
        if (! config('services.google_calendar.client_id') || ! config('services.google_calendar.client_secret') || ! config('services.google_calendar.redirect_uri')) {
            throw new RuntimeException('Google Calendar is not configured.');
        }
    }
}
