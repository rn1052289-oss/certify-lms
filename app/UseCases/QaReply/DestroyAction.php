<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;

/**
 * 質問スレッドの回答を物理削除するユースケース。
 *
 * 削除権限は Controller から QaReplyPolicy::delete で確認する。
 */
final class DestroyAction
{
    public function __invoke(QaReply $reply): void
    {
        $reply->delete();
    }
}
