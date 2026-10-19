<?php

declare(strict_types=1);

use App\Models\MockExamSession;
use App\UseCases\MockExamSession\DestroyAction;
use App\UseCases\MockExamSession\StartAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $operation, $sessionId, $barrier] = $argv;
$session = MockExamSession::findOrFail($sessionId);

DB::listen(function ($query) use ($operation, $barrier) {
    if (! str_contains($query->sql, 'exists') || ! str_contains($query->sql, 'mock_exam_sessions')) {
        return;
    }

    touch($barrier.'/'.$operation.'-read');
    $waitFor = $operation === 'start' ? 'release-start' : 'start-committed';
    $deadline = microtime(true) + 10;
    while (! file_exists($barrier.'/'.$waitFor)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('ターム再判定の同期がタイムアウトしました。');
        }
        usleep(10000);
    }
});

touch($barrier.'/'.$operation.'-invoked');
app($operation === 'start' ? StartAction::class : DestroyAction::class)($session);
touch($barrier.'/'.$operation.'-committed');
