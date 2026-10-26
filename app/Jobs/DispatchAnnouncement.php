<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
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
                    $notification = new AdminAnnouncementNotification($announcement);
                    $notification->id = $row->notification_id;
                    $users[$row->user_id]->notify($notification);
                }
            }, 'user_id');

        if (! DB::table('announcement_recipients')->where('announcement_id', $announcement->id)->exists()) {
            $announcement->update(['dispatched_at' => now()]);
        }
    }
}
