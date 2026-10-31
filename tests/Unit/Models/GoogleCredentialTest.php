<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GoogleCredential モデルのリレーション・Cast・非表示属性を検証する Unit テスト。
 * 1 リレーション (user) + 2 cast (expires_at / connected_at) + hidden (トークン) を網羅する。
 */
class GoogleCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_returns_connected_coach(): void
    {
        $coach = User::factory()->coach()->create();

        $credential = $this->createCredential($coach);

        $this->assertTrue($credential->user->is($coach));
    }

    public function test_timestamps_are_cast_to_carbon(): void
    {
        $credential = $this->createCredential(User::factory()->coach()->create())->fresh();

        $this->assertInstanceOf(Carbon::class, $credential->expires_at);
        $this->assertInstanceOf(Carbon::class, $credential->connected_at);
    }

    public function test_tokens_are_hidden_from_serialization(): void
    {
        $serialized = $this->createCredential(User::factory()->coach()->create())->fresh()->toArray();

        $this->assertSame('primary', $serialized['calendar_id']);
        $this->assertArrayNotHasKey('access_token', $serialized);
        $this->assertArrayNotHasKey('refresh_token', $serialized);
    }

    private function createCredential(User $coach): GoogleCredential
    {
        return GoogleCredential::create([
            'user_id' => $coach->id,
            'access_token' => 'access-test',
            'refresh_token' => 'refresh-test',
            'expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'connected_at' => now(),
        ]);
    }
}
