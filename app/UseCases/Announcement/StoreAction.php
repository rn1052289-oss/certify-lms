<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\DispatchAnnouncement;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** 受付内容と対象者を同じトランザクションで確定し、コミット後に配信を投入する。 */
final class StoreAction
{
    /** @return array{announcement: Announcement} */
    public function __invoke(User $admin, array $validated): array
    {
        return DB::transaction(function () use ($admin, $validated): array {
            $type = AnnouncementTargetType::from($validated['target_type']);
            $announcement = Announcement::query()->create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'target_type' => $type,
                'target_certification_id' => $type === AnnouncementTargetType::Certification ? $validated['target_certification_id'] : null,
                'target_user_id' => $type === AnnouncementTargetType::User ? $validated['target_user_id'] : null,
                'created_by_user_id' => $admin->id,
            ]);
            $recipients = User::query()->where('role', UserRole::Student)->where('status', UserStatus::InProgress);
            if ($type === AnnouncementTargetType::Certification) {
                $recipients->whereHas('enrollments', fn (Builder $query) => $query
                    ->where('certification_id', $announcement->target_certification_id)
                    ->where('status', EnrollmentStatus::Learning));
            } elseif ($type === AnnouncementTargetType::User) {
                $recipients->whereKey($announcement->target_user_id);
            }

            // MySQLのINSERT SELECTで受付時の対象と通知IDを保存し、HTTPで人数分の送信ループを回さない。
            DB::table('announcement_recipients')->insertUsing(
                ['announcement_id', 'user_id', 'notification_id'],
                $recipients->selectRaw('? AS announcement_id, users.id AS user_id, UUID() AS notification_id', [$announcement->id])->toBase(),
            );
            DB::afterCommit(fn () => DispatchAnnouncement::dispatch($announcement->id));

            return ['announcement' => $announcement];
        });
    }
}
