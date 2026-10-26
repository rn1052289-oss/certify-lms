<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\NotificationDelivery;
use App\Notifications\MeetingReminderNotification;
use App\Services\NotificationDeliveryService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 同じ面談の同時実行を直列化し、未投入のチャネルだけをコミット後にキューへ渡す。 */
final class SendReminderAction
{
    /** @return array{queued: int, failed: int} */
    public function __invoke(Meeting $meeting, string $window, CarbonInterface $now): array
    {
        return DB::transaction(function () use ($meeting, $window, $now): array {
            $meeting = Meeting::query()->lockForUpdate()->find($meeting->id);
            $queued = 0;
            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved
                || $meeting->scheduled_at->lte($now)
                || $meeting->scheduled_at->gt($now->copy()->addHours($window === 'eve' ? 24 : 1))) {
                return ['queued' => 0, 'failed' => 0];
            }

            foreach (['student' => UserRole::Student, 'coach' => UserRole::Coach] as $relation => $role) {
                $recipient = $meeting->{$relation};
                if ($recipient === null || $recipient->role !== $role || $recipient->status !== UserStatus::InProgress) {
                    continue;
                }
                $delivery = MeetingReminderDelivery::query()->firstOrCreate([
                    'meeting_id' => $meeting->id, 'user_id' => $recipient->id, 'window' => $window,
                ], ['notification_id' => (string) Str::uuid()]);
                $channels = [];
                foreach (['database' => 'database_sent_at', 'mail' => 'mail_sent_at'] as $channel => $sentAt) {
                    if ($delivery->{$sentAt} === null && ! NotificationDelivery::query()
                        ->where('notification_id', $delivery->notification_id)->where('channel', $channel)->whereNotNull('queued_at')->exists()) {
                        $channels[] = $channel;
                    }
                }
                if ($channels === []) {
                    continue;
                }
                $notification = new MeetingReminderNotification($meeting, $window, $delivery->id, $channels);
                $notification->id = $delivery->notification_id;
                foreach ($channels as $channel) {
                    app(NotificationDeliveryService::class)->plan($notification->id, $recipient, $channel, [
                        'reminder_delivery_id' => $delivery->id,
                    ]);
                }
                DB::afterCommit(function () use ($recipient, $notification, $channels): void {
                    $recipient->notify($notification);
                    NotificationDelivery::query()->where('notification_id', $notification->id)
                        ->whereIn('channel', $channels)->update(['queued_at' => now()]);
                });
                $queued += count($channels);
            }

            return ['queued' => $queued, 'failed' => 0];
        });
    }
}
