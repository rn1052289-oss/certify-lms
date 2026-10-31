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

/**
 * 受付内容と対象者を同じトランザクションで確定し、コミット後に配信を投入する。
 *
 * お知らせを受け付け、配信ジョブを投入するユースケース。
 *
 * 処理フロー(1 〜 3 は DB::transaction() 内):
 * 1. Announcement を作成する
 * 2. 受付時点の配信対象(受講中の受講生。資格指定ならその資格を学習中の受講生)と通知 ID を
 *    announcement_recipients に INSERT SELECT で保存する(HTTP 内で人数分のループを回さない)
 * 3. コミット後に DispatchAnnouncement を投入する。投入に失敗しても受付は残し、再開コマンドで投入できる
 */
final class StoreAction
{
    /**
     * @param array{title: string, body: string, target_type: string, target_certification_id?: string, target_user_id?: string} $validated
     *
     * @return array{announcement: Announcement}
     */
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
            DB::afterCommit(function () use ($announcement): void {
                try {
                    DispatchAnnouncement::dispatch($announcement->id);
                } catch (\Throwable $exception) {
                    // 受付と対象者は保存済み。再開コマンドで同じ受付を投入できる。
                    report($exception);
                }
            });

            return ['announcement' => $announcement];
        });
    }
}
