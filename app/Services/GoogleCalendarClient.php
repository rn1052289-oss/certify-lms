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

    private function token(array $parameters, ?float $deadline = null): array
    {
        $this->ensureConfigured();
        $timeout = $this->remainingTimeout($deadline);
        $response = Http::asForm()->withOptions(['timeout' => $timeout, 'connect_timeout' => min(3, $timeout)])->post('https://oauth2.googleapis.com/token', $parameters + [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
        ]);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null) || ($data['expires_in'] ?? 0) <= 0) {
            throw new RuntimeException('Google OAuth token request failed.');
        }

        return $data;
    }

    private function authorized(GoogleCredential $credential, ?float $deadline = null): PendingRequest
    {
        if ($credential->expires_at->lessThanOrEqualTo(now()->addMinute())) {
            $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $credential->refresh_token], $deadline);
            $credential->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $credential->refresh_token,
                'expires_at' => now()->addSeconds((int) $token['expires_in']),
            ]);
        }

        $timeout = $this->remainingTimeout($deadline);

        return Http::withToken($credential->access_token)->acceptJson()->withOptions(['timeout' => $timeout, 'connect_timeout' => min(3, $timeout)]);
    }

    public function busy(GoogleCredential $credential, CarbonInterface $start, CarbonInterface $end, ?float $deadline = null): array
    {
        $response = $this->authorized($credential, $deadline)->post('https://www.googleapis.com/calendar/v3/freeBusy', [
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

    private function remainingTimeout(?float $deadline): float
    {
        $remaining = $deadline === null ? 5 : $deadline - $this->monotonicSeconds();
        // cURL のミリ秒変換で 0 (=無期限) になる短さでは送信しない。
        if ($remaining <= 0.001) {
            throw new RuntimeException('Google freeBusy time budget exceeded.');
        }

        return min(5, $remaining);
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
