<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問スレッドへの回答投稿ユースケース。
 *
 * 回答投稿権限と入力値の検証は StoreRequest で行う。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaThread $thread, User $author, array $validated): QaReply
    {
        return $thread->replies()->create([
            'user_id' => $author->id,
            'body' => $validated['body'],
        ]);
    }
}
