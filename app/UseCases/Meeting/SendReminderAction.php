<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Notifications\MeetingReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SendReminderAction
{
    /** @return array{sent: int, failed: int} */
    public function __invoke(Meeting $meeting, string $window, CarbonInterface $now): array
    {
        return DB::transaction(function () use ($meeting, $window, $now): array {
            // 同じ面談の並行起動とキャンセルを直列化し、最新の状態を再確認する。
            $meeting = Meeting::query()->lockForUpdate()->find($meeting->id);
            $hours = $window === 'eve' ? 24 : 1;
            $result = ['sent' => 0, 'failed' => 0];

            if ($meeting === null || $meeting->status !== MeetingStatus::Reserved
                || $meeting->scheduled_at->lte($now) || $meeting->scheduled_at->gt($now->copy()->addHours($hours))) {
                return $result;
            }

            foreach (['student' => UserRole::Student, 'coach' => UserRole::Coach] as $relation => $role) {
                $recipient = $meeting->{$relation};

                if ($recipient === null || $recipient->role !== $role || $recipient->status !== UserStatus::InProgress) {
                    continue;
                }

                $delivery = MeetingReminderDelivery::query()->firstOrCreate([
                    'meeting_id' => $meeting->id, 'user_id' => $recipient->id, 'window' => $window,
                ], ['notification_id' => (string) Str::uuid()]);
                $notification = new MeetingReminderNotification($meeting, $window);
                $notification->id = $delivery->notification_id;

                foreach (['database', 'mail'] as $channel) {
                    $sentAt = $channel.'_sent_at';

                    if ($delivery->{$sentAt} !== null) {
                        continue;
                    }

                    try {
                        $recipient->notifyNow($notification, [$channel]);
                        $delivery->update([$sentAt => $now]);
                        $result['sent']++;
                    } catch (\Throwable $exception) {
                        // 成功済みチャネルは保持し、次の定期実行で失敗分だけを再試行する。
                        $result['failed']++;
                        report($exception);
                    }
                }
            }

            return $result;
        });
    }
}
