<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $studentId, $enrollmentId, $scheduledAt, $barrier, $worker] = $argv;
Notification::fake();
Auth::guard('web')->setUser(User::query()->findOrFail($studentId));

// 両リクエストが「空きコーチあり」を取得した後、同時に予約保存へ進む。
DB::listen(function (QueryExecuted $query) use ($barrier, $worker): void {
    if (! str_contains($query->sql, 'coach_availabilities') || ! str_contains($query->sql, 'not exists')) {
        return;
    }

    touch($barrier.'/'.$worker);
    $deadline = microtime(true) + 10;
    while (count(glob($barrier.'/*')) < 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('並行予約の同期がタイムアウトしました。');
        }
        usleep(10000);
    }
});

$request = Request::create('/enrollments/'.$enrollmentId.'/meetings', 'POST', [
    'scheduled_at' => $scheduledAt,
    'topic' => '並行予約の確認',
], [], [], ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
