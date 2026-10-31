<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Models\MeetingReminderDelivery;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 面談 1 件のリマインダー(eve: 前日 / one_hour_before: 1 時間前)を受講生とコーチへ送るユースケース。
 *
 * 面談行のロック後に reserved かつ対象時間内かを再確認し、受講中の当事者にだけ送る。
 * 送信記録(MeetingReminderDelivery)で受信者 × 時間帯ごとの二重送信を防ぐ。アプリ内通知はロック内で送り、
 * メールは面談のロックを解放してから受信者ごとに送る。
 */
final class SendReminderAction
{
    /**
     * @param string $window eve / one_hour_before
     *
     * @return array{sent: int, failed: int}
     */
    public function __invoke(Meeting $meeting, string $window, CarbonInterface $now): array
    {
        $result = DB::transaction(function () use ($meeting, $window, $now): array {
            // 同じ面談の並行起動とキャンセルを直列化し、最新の状態を再確認する。
            $meeting = Meeting::query()->lockForUpdate()->find($meeting->id);
            $hours = $window === 'eve' ? 24 : 1;
            $result = ['sent' => 0, 'failed' => 0, 'deliveries' => []];

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

                if ($delivery->database_sent_at === null) {
                    try {
                        $recipient->notifyNow($notification, ['database']);
                        $delivery->update(['database_sent_at' => $now]);
                        $result['sent']++;
                    } catch (\Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }

                if ($delivery->mail_sent_at === null) {
                    $result['deliveries'][] = ['id' => $delivery->id, 'user_id' => $recipient->id];
                }
            }

            return $result;
        });

        // 面談のロックを解放してから外部SMTPへ接続する。
        foreach ($result['deliveries'] as $pending) {
            DB::transaction(function () use ($pending, $meeting, $window, $now, &$result): void {
                // 当該受信者・当該タイミングの配信行だけをロックする。
                $delivery = MeetingReminderDelivery::query()->lockForUpdate()->findOrFail($pending['id']);

                if ($delivery->mail_sent_at !== null) {
                    return;
                }

                $currentMeeting = Meeting::query()->find($meeting->id);
                $recipient = User::query()->find($pending['user_id']);
                $role = $pending['user_id'] === $currentMeeting?->student_id ? UserRole::Student : UserRole::Coach;

                if ($currentMeeting === null || $currentMeeting->status !== MeetingStatus::Reserved
                    || $currentMeeting->scheduled_at->lte($now)
                    || $currentMeeting->scheduled_at->gt($now->copy()->addHours($window === 'eve' ? 24 : 1))
                    || $recipient === null || $recipient->role !== $role || $recipient->status !== UserStatus::InProgress) {
                    return;
                }

                try {
                    $notification = new MeetingReminderNotification($currentMeeting, $window);
                    $notification->id = $delivery->notification_id;
                    $recipient->notifyNow($notification, ['mail']);
                    $delivery->update(['mail_sent_at' => $now]);
                    $result['sent']++;
                } catch (\Throwable $exception) {
                    $result['failed']++;
                    report($exception);
                }
            });
        }

        return ['sent' => $result['sent'], 'failed' => $result['failed']];
    }
}
