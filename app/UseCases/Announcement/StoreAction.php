<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 配信履歴を先に保存し、同期通知の部分失敗でも配信済み実績を残す。
 * メールはDBロールバックでは取り消せないため、通知先ごとに結果を記録する。
 */
final class StoreAction
{
    /** @return array{announcement: Announcement, failedCount: int} */
    public function __invoke(User $admin, array $validated): array
    {
        $type = AnnouncementTargetType::from($validated['target_type']);
        $announcement = Announcement::query()->create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'target_type' => $type,
            'target_certification_id' => $type === AnnouncementTargetType::Certification ? $validated['target_certification_id'] : null,
            'target_user_id' => $type === AnnouncementTargetType::User ? $validated['target_user_id'] : null,
            'created_by_user_id' => $admin->id,
            'dispatched_at' => now(),
        ]);

        $failedCount = 0;
        $recipients = User::query()->where('role', UserRole::Student)->where('status', UserStatus::InProgress);

        if ($type === AnnouncementTargetType::Certification) {
            $recipients->whereHas('enrollments', fn (Builder $query) => $query
                ->where('certification_id', $announcement->target_certification_id)
                ->where('status', EnrollmentStatus::Learning));
        } elseif ($type === AnnouncementTargetType::User) {
            $recipients->whereKey($announcement->target_user_id);
        }

        $recipients->chunkById(100, function ($users) use ($announcement, &$failedCount): void {
            foreach ($users as $user) {
                $notification = new AdminAnnouncementNotification($announcement);
                $notification->id = (string) Str::uuid();

                try {
                    $user->notify($notification);
                } catch (\Throwable $exception) {
                    $failedCount++;
                    $failedUserIds = $announcement->failed_user_ids ?? [];
                    $failedUserIds[] = $user->id;
                    $announcement->update([
                        'failed_count' => $failedCount,
                        'failed_user_ids' => $failedUserIds,
                    ]);
                    Log::error('お知らせの通知配信に失敗しました。', [
                        'announcement_id' => $announcement->id,
                        'recipient_user_id' => $user->id,
                    ]);
                    report($exception);
                }

                // database チャネル成功後にメールが失敗した場合も受信実績を数える。
                if ($user->notifications()->whereKey($notification->id)->exists()) {
                    $announcement->increment('dispatched_count');
                }
            }
        });

        return ['announcement' => $announcement, 'failedCount' => $failedCount];
    }
}
