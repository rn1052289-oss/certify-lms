<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;

/**
 * 回答投稿者本人による回答編集ユースケース。
 *
 * 質問・投稿者は変更せず、回答本文のみ更新する。
 * 編集権限と入力値の検証は UpdateRequest で行う。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaReply $reply, array $validated): QaReply
    {
        $reply->update(['body' => $validated['body']]);

        return $reply->refresh();
    }
}
