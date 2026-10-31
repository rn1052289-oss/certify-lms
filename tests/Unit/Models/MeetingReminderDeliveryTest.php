<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MeetingReminderDelivery モデルの Cast と送信記録の一意性を検証する Unit テスト。
 * 2 cast (database_sent_at / mail_sent_at) と、同じ面談・時間帯・受信者の記録が 1 件に限られることを網羅する。
 */
class MeetingReminderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_timestamps_are_cast_to_carbon_and_nullable(): void
    {
        $delivery = $this->createDelivery(Meeting::factory()->create(), User::factory()->student()->create(), [
            'database_sent_at' => '2026-10-15 09:00:00',
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $delivery->database_sent_at);
        $this->assertNull($delivery->mail_sent_at);
    }

    public function test_same_meeting_window_and_recipient_cannot_be_recorded_twice(): void
    {
        $meeting = Meeting::factory()->create();
        $student = User::factory()->student()->create();
        $this->createDelivery($meeting, $student);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createDelivery($meeting, $student);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createDelivery(Meeting $meeting, User $user, array $attributes = []): MeetingReminderDelivery
    {
        return MeetingReminderDelivery::create(array_merge([
            'meeting_id' => $meeting->id,
            'user_id' => $user->id,
            'window' => 'eve',
            'notification_id' => (string) Str::uuid(),
        ], $attributes));
    }
}
