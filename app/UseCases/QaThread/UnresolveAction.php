<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

/**
 * 質問スレッドを未解決に戻すユースケース。
 *
 * 未解決に戻す権限は Controller から QaThreadPolicy::unresolve で確認する。
 */
final class UnresolveAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        $thread->update([
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);

        return $thread->refresh();
    }
}
