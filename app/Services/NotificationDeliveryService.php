<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Announcement;
use App\Models\MeetingReminderDelivery;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * チャネル単位の成功記録と、お知らせの実配信・最終失敗件数を管理する。
 *
 * 通知の受信者 × チャネルごとの配信記録(NotificationDelivery)を管理する Service。
 *
 * キュー投入前に記録を作り(plan)、送信完了(sent) / 最終失敗(failed)を記録する。
 * お知らせの配信件数・失敗者は、配信記録から集計し直して Announcement に反映する(refreshAnnouncement)。
 */
final class NotificationDeliveryService
{
    /**
     * 配信記録を作成し(既にあればそれを使い)、その ID を返す。
     *
     * @param array<string, mixed> $metadata announcement_id / reminder_delivery_id など
     */
    public function plan(string $notificationId, User $recipient, string $channel, array $metadata = []): int
    {
        return NotificationDelivery::query()->firstOrCreate([
            'notification_id' => $notificationId,
            'user_id' => $recipient->id,
            'channel' => $channel,
        ], $metadata)->id;
    }

    /**
     * 送信完了を記録する。面談リマインダーの配信なら、MeetingReminderDelivery の送信日時も更新する。
     */
    public function sent(NotificationDelivery $delivery): void
    {
        $delivery->update(['sent_at' => now(), 'failed_at' => null]);

        if ($delivery->reminder_delivery_id !== null) {
            MeetingReminderDelivery::query()->whereKey($delivery->reminder_delivery_id)
                ->update([$delivery->channel === 'database' ? 'database_sent_at' : 'mail_sent_at' => now()]);
        }
    }

    /**
     * 最終失敗を記録し、お知らせの配信なら集計を更新する。送信完了済みの記録は失敗にしない。
     */
    public function failed(int $id, ?string $announcementId = null): void
    {
        $announcementId = DB::transaction(function () use ($id, $announcementId): ?string {
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($id);
            if ($delivery === null || $delivery->sent_at !== null) {
                return $announcementId;
            }
            $delivery->update(['failed_at' => now()]);

            return $delivery->announcement_id;
        });
        $this->refreshAnnouncement($announcementId);
    }

    /**
     * 展開ジョブが上限回数に達したとき、キューへ渡せなかったチャネルだけを最終失敗にする。
     *
     * 配信記録の無いチャネルも作成したうえで、未投入のものを最終失敗にし、集計を更新する。
     */
    public function failUnqueuedAnnouncementDeliveries(string $id): void
    {
        DB::table('announcement_recipients')->where('announcement_id', $id)
            ->orderBy('user_id')->chunkById(100, function ($rows) use ($id): void {
                DB::transaction(function () use ($rows, $id): void {
                    $records = [];
                    foreach ($rows as $row) {
                        foreach (['database', 'mail'] as $channel) {
                            $records[] = [
                                'notification_id' => $row->notification_id,
                                'user_id' => $row->user_id,
                                'channel' => $channel,
                                'announcement_id' => $id,
                                'created_at' => now(), 'updated_at' => now(),
                            ];
                        }
                    }
                    NotificationDelivery::query()->insertOrIgnore($records);
                    NotificationDelivery::query()->where('announcement_id', $id)
                        ->whereIn('notification_id', $rows->pluck('notification_id'))
                        ->whereNull('queued_at')->whereNull('sent_at')->whereNull('failed_at')
                        ->update(['failed_at' => now()]);
                });
            }, 'user_id');
        $this->refreshAnnouncement($id);
    }

    /**
     * 配信のトランザクションが終わってから、お知らせの集計だけを直列化する。
     *
     * 配信件数・失敗者を集計し直し、全受信者 × 2 チャネルが完了したときに dispatched_at を確定する。
     */
    public function refreshAnnouncement(?string $id): void
    {
        if ($id === null) {
            return;
        }

        DB::transaction(function () use ($id): void {
            $announcement = Announcement::query()->lockForUpdate()->findOrFail($id);
            $deliveries = NotificationDelivery::query()->where('announcement_id', $id);
            $failedUserIds = (clone $deliveries)->whereNotNull('failed_at')->whereNull('sent_at')
                ->distinct()->orderBy('user_id')->pluck('user_id')->all();
            $expected = DB::table('announcement_recipients')->where('announcement_id', $id)->count() * 2;
            $finished = (clone $deliveries)->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('failed_at'))->count();
            $dispatchedCount = (clone $deliveries)->where('channel', 'database')->whereNotNull('sent_at')->count();
            $resultsChanged = $announcement->dispatched_count !== $dispatchedCount
                || $announcement->failed_user_ids !== $failedUserIds;

            $announcement->update([
                'dispatched_count' => $dispatchedCount,
                'failed_count' => count($failedUserIds),
                'failed_user_ids' => $failedUserIds,
                'dispatched_at' => $finished === $expected
                    ? ($resultsChanged ? now() : ($announcement->dispatched_at ?? now())) : null,
            ]);
        });
    }
}
