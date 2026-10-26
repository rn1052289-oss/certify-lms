<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\UseCases\Meeting\SendReminderAction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : eve または one_hour_before}';

    protected $description = '予約済み面談の24時間前・1時間前のリマインダーを配信する';

    public function handle(SendReminderAction $action): int
    {
        $window = $this->option('window');

        if (! in_array($window, ['eve', 'one_hour_before'], true)) {
            $this->error('--window は eve または one_hour_before を指定してください。');

            return self::FAILURE;
        }

        $now = now();
        $queued = 0;
        $failed = 0;

        // 通知時刻を過ぎた開始前の予約を対象にし、遅延・配信失敗は次回実行で拾う。
        Meeting::query()
            ->where('status', MeetingStatus::Reserved)
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $now->copy()->addHours($window === 'eve' ? 24 : 1))
            ->where(function (Builder $query) use ($window): void {
                foreach (['student' => UserRole::Student, 'coach' => UserRole::Coach] as $relation => $role) {
                    $query->orWhere(function (Builder $query) use ($window, $relation, $role): void {
                        $query->whereHas($relation, fn (Builder $user) => $user
                            ->where('role', $role)->where('status', UserStatus::InProgress))
                            ->whereDoesntHave('reminderDeliveries', fn (Builder $delivery) => $delivery
                                ->where('window', $window)
                                ->whereColumn('meeting_reminder_deliveries.user_id', 'meetings.'.$relation.'_id')
                                ->whereNotNull('database_sent_at')->whereNotNull('mail_sent_at'));
                    });
                }
            })
            ->chunkById(100, function ($meetings) use ($action, $window, $now, &$queued, &$failed): void {
                foreach ($meetings as $meeting) {
                    try {
                        $result = $action($meeting, $window, $now);
                        $queued += $result['queued'];
                        $failed += $result['failed'];
                    } catch (\Throwable $exception) {
                        $failed++;
                        report($exception);
                    }
                }
            });

        $this->info("面談リマインダー（{$window}）：受付 {$queued} チャネル、投入失敗 {$failed} 件。");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
