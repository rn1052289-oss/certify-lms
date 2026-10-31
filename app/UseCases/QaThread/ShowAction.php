<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

/**
 * 質問掲示板のスレッド詳細を取得するユースケース。
 *
 * 資格・質問投稿者・回答と回答投稿者・回答件数を取得する。
 * 閲覧権限は Controller で QaThreadPolicy::view により確認する。
 */
final class ShowAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        $thread->load([
            'certification',
            'user',
            'replies' => fn ($query) => $query
                ->with('user')
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);

        $thread->loadCount('replies');

        return $thread;
    }
}
