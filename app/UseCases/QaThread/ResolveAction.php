<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

/**
 * 質問スレッドを解決済みにするユースケース。
 *
 * 解決操作の権限は Controller から QaThreadPolicy::resolve で確認する。
 */
final class ResolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        $thread->update([
            'status' => QaThreadStatus::Resolved->value,
            'resolved_at' => now(),
        ]);

        return $thread->refresh();
    }
}
