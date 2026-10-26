<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Announcement;
use App\Models\MeetingReminderDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** チャネル単位の成功記録と、お知らせの実配信・最終失敗件数を管理する。 */
final class NotificationDeliveryService
{
    public function plan(string $notificationId, User $recipient, string $channel, array $metadata = []): int
    {
        return NotificationDelivery::query()->firstOrCreate([
            'notification_id' => $notificationId,
            'user_id' => $recipient->id,
            'channel' => $channel,
        ], $metadata)->id;
    }

    public function sent(NotificationDelivery $delivery): void
    {
        $delivery->update(['sent_at' => now(), 'failed_at' => null]);

        if ($delivery->reminder_delivery_id !== null) {
            MeetingReminderDelivery::query()->whereKey($delivery->reminder_delivery_id)
                ->update([$delivery->channel === 'database' ? 'database_sent_at' : 'mail_sent_at' => now()]);
        }

        $this->refreshAnnouncement($delivery->announcement_id);
    }

    public function failed(int $id, ?string $announcementId = null): void
    {
        DB::transaction(function () use ($id, $announcementId): void {
            if ($announcementId !== null) {
                Announcement::query()->lockForUpdate()->findOrFail($announcementId);
            }
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($id);
            if ($delivery === null || $delivery->sent_at !== null) {
                return;
            }
            $delivery->update(['failed_at' => now()]);
            $this->refreshAnnouncement($delivery->announcement_id);
        });
    }

    private function refreshAnnouncement(?string $id): void
    {
        if ($id === null) {
            return;
        }

        $announcement = Announcement::query()->lockForUpdate()->findOrFail($id);
        $deliveries = NotificationDelivery::query()->where('announcement_id', $id);
        $failedUserIds = (clone $deliveries)->whereNotNull('failed_at')->whereNull('sent_at')
            ->distinct()->orderBy('user_id')->pluck('user_id')->all();
        $expected = DB::table('announcement_recipients')->where('announcement_id', $id)->count() * 2;
        $finished = (clone $deliveries)->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('failed_at'))->count();

        $announcement->update([
            'dispatched_count' => (clone $deliveries)->where('channel', 'database')->whereNotNull('sent_at')->count(),
            'failed_count' => count($failedUserIds),
            'failed_user_ids' => $failedUserIds,
            'dispatched_at' => $finished === $expected ? now() : null,
        ]);
    }
}
