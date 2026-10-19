<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 接続済み/未接続表示の検証用。ダミートークンで実際の Google 接続は成立しない。 */
final class GoogleCalendarVerificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Google Calendar verification data is local/testing only.');
        }
        $coach = User::query()->where('email', 'coach@certify-lms.test')->firstOrFail();
        $unlinked = User::query()->where('email', 'coach2@certify-lms.test')->firstOrFail();
        if ($unlinked->googleCredential()->exists()) {
            throw new \RuntimeException('The unlinked verification coach already has a connection.');
        }
        // 実トークンを上書きせず、デモを繰り返しても接続IDを変えない。
        GoogleCredential::query()->firstOrCreate(['user_id' => $coach->id], [
            'access_token' => 'verification-dummy-access-token',
            'refresh_token' => 'verification-dummy-refresh-token',
            'expires_at' => now()->subHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);
    }
}
