<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingGoogleEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingGoogleEvent モデルのリレーションを検証する Unit テスト。
 * 1 リレーション (credential) と、連携解除後 (google_credential_id が null) の扱いを網羅する。
 */
class MeetingGoogleEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_relation_returns_google_credential(): void
    {
        $credential = $this->createCredential();

        $event = $this->createEvent($credential->id);

        $this->assertTrue($event->credential->is($credential));
    }

    public function test_credential_relation_returns_null_after_credential_is_removed(): void
    {
        $credential = $this->createCredential();
        $event = $this->createEvent($credential->id);

        $credential->delete();

        $this->assertNull($event->fresh()->credential);
    }

    private function createEvent(?string $credentialId): MeetingGoogleEvent
    {
        return MeetingGoogleEvent::create([
            'meeting_id' => Meeting::factory()->create()->id,
            'google_credential_id' => $credentialId,
            'calendar_id' => 'primary',
            'event_id' => 'event-test',
        ]);
    }

    private function createCredential(): GoogleCredential
    {
        return GoogleCredential::create([
            'user_id' => User::factory()->coach()->create()->id,
            'access_token' => 'access-test',
            'refresh_token' => 'refresh-test',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);
    }
}
