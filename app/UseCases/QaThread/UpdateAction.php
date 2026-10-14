<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 投稿者本人による質問スレッドの編集ユースケース。
 *
 * 資格・投稿者・解決状態は変更せず、タイトルと本文のみ更新する。
 * 編集権限と入力値の検証は UpdateRequest で行う。
 */
final class UpdateAction
{
    /**
     * @param array{title: string, body: string} $validated
     */
    public function __invoke(QaThread $thread, array $validated): QaThread
    {
        $thread->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
        ]);

        return $thread->refresh();
    }
}
