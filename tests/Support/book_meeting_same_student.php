<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $studentId, $enrollmentId, $scheduledAt, $barrier, $worker] = $argv;
Notification::fake();
Auth::guard('web')->setUser(User::query()->findOrFail($studentId));

$arrive = function (string $stage, bool $required) use ($barrier, $worker): void {
    touch($barrier.'/'.$stage.'-'.$worker);
    $deadline = microtime(true) + ($required ? 10 : 1);
    while (count(glob($barrier.'/'.$stage.'-*')) < 2) {
        if (microtime(true) > $deadline) {
            if ($required) {
                throw new RuntimeException('並行予約の同期がタイムアウトしました。');
            }

            return;
        }
        usleep(10000);
    }
};

// 両リクエストの予約トランザクション開始を揃える。
$began = false;
Event::listen(TransactionBeginning::class, function () use (&$began, $arrive): void {
    if ($began) {
        return;
    }

    $began = true;
    $arrive('begin', true);
});

// 空きコーチ取得後も揃える。受講生のロックで片方が待たされる場合は、1秒で先に進める。
DB::listen(function (QueryExecuted $query) use ($arrive): void {
    if (str_contains($query->sql, 'coach_availabilities') && str_contains($query->sql, 'not exists')) {
        $arrive('slot', false);
    }
});

$request = Request::create('/enrollments/'.$enrollmentId.'/meetings', 'POST', [
    'scheduled_at' => $scheduledAt,
    'topic' => '同時予約の確認',
], [], [], ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
