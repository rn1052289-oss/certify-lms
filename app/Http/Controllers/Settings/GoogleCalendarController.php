<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GoogleCredential;
use App\Services\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** role:coach の本人だけが OAuth を開始・完了・解除できる。 */
/**
 * コーチの Google Calendar 連携(OAuth 同意画面への遷移 / callback での接続 / 連携解除) Controller。
 */
class GoogleCalendarController extends Controller
{
    public function redirect(Request $request, GoogleCalendarClient $client): RedirectResponse
    {
        $state = Str::random(64);
        $request->session()->put('google_calendar_oauth', [
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);
        try {
            return redirect()->away($client->authorizationUrl($state));
        } catch (Throwable) {
            $request->session()->forget('google_calendar_oauth');

            return redirect()->route('settings.availability.index')->with('error', 'Google Calendarの連携設定を確認してください。');
        }
    }

    public function callback(Request $request, GoogleCalendarClient $client): RedirectResponse
    {
        // 一度取り出した state は、失敗した場合でも再利用できない。
        $oauth = $request->session()->pull('google_calendar_oauth');
        $state = $request->query('state');
        abort_unless(is_array($oauth) && is_string($state) && hash_equals($oauth['state'], $state)
            && $oauth['user_id'] === $request->user()->id && $oauth['expires_at'] >= now()->timestamp, 403);

        if ($request->has('error')) {
            return redirect()->route('settings.availability.index')->with('error', 'Google Calendarの連携を中止しました。');
        }
        $validated = $request->validate(['code' => ['required', 'string', 'max:4096']]);
        try {
            $token = $client->exchangeCode($validated['code']);
            // 別アカウントの refresh token を引き継がない。offline consent で新規取得する。
            if (! is_string($token['refresh_token'] ?? null) || $token['refresh_token'] === '') {
                throw new \RuntimeException('Google refresh token missing.');
            }
            // 再接続も新しい接続IDにし、古い予定の対応表とは切り離す。
            DB::transaction(function () use ($request, $token): void {
                $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $user->googleCredential()->delete();
                GoogleCredential::create([
                    'user_id' => $user->id,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                    'expires_at' => now()->addSeconds((int) $token['expires_in']),
                    'calendar_id' => 'primary',
                    'connected_at' => now(),
                ]);
            });
        } catch (Throwable) {
            return redirect()->route('settings.availability.index')->with('error', 'Google Calendarとの連携に失敗しました。再度お試しください。');
        }

        return redirect()->route('settings.availability.index')->with('success', 'Google Calendarと連携しました。');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget('google_calendar_oauth');
        DB::transaction(function () use ($request): void {
            $user = $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->googleCredential()->delete();
        });

        return redirect()->route('settings.availability.index')->with('success', 'Google Calendarの連携を解除しました。');
    }
}
