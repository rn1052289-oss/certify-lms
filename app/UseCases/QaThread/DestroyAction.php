<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 質問スレッドを物理削除するユースケース。
 *
 * 最新の回答有無と削除権限を質問行のロック後に確認する。
 */
final class DestroyAction
{
    public function __invoke(QaThread $thread, User $user): void
    {
        DB::transaction(function () use ($thread, $user) {
            $lockedThread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);

            Gate::forUser($user)->authorize('delete', $lockedThread);

            $lockedThread->delete();
        });
    }
}
