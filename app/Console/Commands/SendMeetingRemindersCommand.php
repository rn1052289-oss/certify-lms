<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\UseCases\Meeting\SendReminderAction;
use Illuminate\Console\Command;

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
        $sent = 0;
        $failed = 0;

        // 通知時刻を過ぎた開始前の予約を対象にし、遅延・配信失敗は次回実行で拾う。
        Meeting::query()
            ->where('status', MeetingStatus::Reserved)
            ->where('scheduled_at', '>', $now)
            ->where('scheduled_at', '<=', $now->copy()->addHours($window === 'eve' ? 24 : 1))
            ->chunkById(100, function ($meetings) use ($action, $window, $now, &$sent, &$failed): void {
                foreach ($meetings as $meeting) {
                    $result = $action($meeting, $window, $now);
                    $sent += $result['sent'];
                    $failed += $result['failed'];
                }
            });

        $this->info("面談リマインダー（{$window}）：配信 {$sent} チャネル、失敗 {$failed} チャネル。");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
