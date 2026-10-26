<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DispatchAnnouncement;
use App\Models\Announcement;
use Illuminate\Console\Command;

/** 保存済みの受付・対象者・通知IDを維持して、未投入の配信を再開する。 */
class RetryAnnouncementCommand extends Command
{
    protected $signature = 'notifications:retry-announcement {announcement : お知らせのID}';

    protected $description = '初回投入・配信ジョブ展開に失敗したお知らせを再開する';

    public function handle(): int
    {
        $id = (string) $this->argument('announcement');
        if (! Announcement::query()->whereKey($id)->exists()) {
            $this->error('お知らせが見つかりません。');

            return self::FAILURE;
        }

        try {
            DispatchAnnouncement::dispatch($id);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('キューへ投入できませんでした。接続を復旧して再実行してください。');

            return self::FAILURE;
        }
        $this->info('保存済みのお知らせの配信再開を受け付けました。');

        return self::SUCCESS;
    }
}
