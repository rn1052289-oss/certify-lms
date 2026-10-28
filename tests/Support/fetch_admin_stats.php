<?php

declare(strict_types=1);

use App\Services\EnrollmentStatsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $barrier, $method] = $argv;
config(['cache.stores.file.path' => $barrier.'/cache']);
Cache::purge('file');

$paused = false;
DB::listen(function (QueryExecuted $query) use ($barrier, &$paused): void {
    if ($paused || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`enrollments`')) {
        return;
    }
    // DBから旧値を取得済み、キャッシュへの保存前で停止する。
    $paused = true;
    touch($barrier.'/ready');
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('管理者集計の同期待ちがタイムアウトしました。');
        }
        usleep(10000);
    }
});
$result = app(EnrollmentStatsService::class)->{$method}();
$passed = $method === 'adminKpi' ? $result['passed_count'] : $result->first()['passed'];
echo json_encode(['passed' => $passed], JSON_THROW_ON_ERROR);
