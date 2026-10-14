<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 質問スレッドを物理削除するユースケース。
 *
 * 削除権限は Controller から QaThreadPolicy::delete で確認する。
 */
final class DestroyAction
{
    public function __invoke(QaThread $thread): void
    {
        $thread->delete();
    }
}
