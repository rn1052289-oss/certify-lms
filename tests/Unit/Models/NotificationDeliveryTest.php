<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NotificationDelivery モデルの Cast と配信記録の一意性を検証する Unit テスト。
 * 3 cast (queued_at / sent_at / failed_at) と、同じ通知・受信者・チャネルの記録が 1 件に限られることを網羅する。
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_timestamps_are_cast_to_carbon_and_nullable(): void
    {
        $delivery = $this->createDelivery(User::factory()->student()->create(), (string) Str::uuid(), [
            'queued_at' => '2026-10-26 09:00:00',
            'sent_at' => '2026-10-26 09:00:05',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $delivery->queued_at);
        $this->assertInstanceOf(Carbon::class, $delivery->sent_at);
        $this->assertNull($delivery->failed_at);
    }

    public function test_same_notification_recipient_and_channel_cannot_be_recorded_twice(): void
    {
        $student = User::factory()->student()->create();
        $notificationId = (string) Str::uuid();
        $this->createDelivery($student, $notificationId);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createDelivery($student, $notificationId);
    }

    public function test_same_notification_can_be_recorded_for_each_channel(): void
    {
        $student = User::factory()->student()->create();
        $notificationId = (string) Str::uuid();

        $this->createDelivery($student, $notificationId, ['channel' => 'database']);
        $this->createDelivery($student, $notificationId, ['channel' => 'mail']);

        $this->assertSame(2, NotificationDelivery::where('notification_id', $notificationId)->count());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createDelivery(User $user, string $notificationId, array $attributes = []): NotificationDelivery
    {
        return NotificationDelivery::create(array_merge([
            'notification_id' => $notificationId,
            'user_id' => $user->id,
            'channel' => 'database',
        ], $attributes));
    }
}
