<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** 受付時に確定した対象者へ、通知を小分けに投入する。HTTP内では配信しない。 */
final class DispatchAnnouncement implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public readonly string $announcementId)
    {
        $this->onQueue('notifications');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $announcement = Announcement::query()->findOrFail($this->announcementId);
        DB::table('announcement_recipients')->where('announcement_id', $announcement->id)
            ->orderBy('user_id')->chunkById(100, function ($rows) use ($announcement): void {
                $users = User::withTrashed()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
                foreach ($rows as $row) {
                    if (! isset($users[$row->user_id])) {
                        continue;
                    }
                    foreach (['database', 'mail'] as $channel) {
                        $id = app(NotificationDeliveryService::class)->plan(
                            $row->notification_id, $users[$row->user_id], $channel,
                            ['announcement_id' => $announcement->id],
                        );
                        $delivery = NotificationDelivery::query()->findOrFail($id);
                        if ($delivery->queued_at !== null || $delivery->sent_at !== null) {
                            continue;
                        }
                        $notification = new AdminAnnouncementNotification($announcement, [$channel]);
                        $notification->id = $row->notification_id;
                        $users[$row->user_id]->notify($notification);
                        $delivery->update(['queued_at' => now()]);
                    }
                }
            }, 'user_id');

        app(NotificationDeliveryService::class)->refreshAnnouncement($announcement->id);
    }

    public function failed(\Throwable $exception): void
    {
        app(NotificationDeliveryService::class)->failUnqueuedAnnouncementDeliveries($this->announcementId);
    }
}
