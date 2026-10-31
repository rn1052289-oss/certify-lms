<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 管理者のお知らせ一覧を取得するユースケース。配信日時(未配信は作成日時)の新しい順に 20 件ずつ返す。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, Announcement>
     */
    public function __invoke(): LengthAwarePaginator
    {
        return Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByRaw('COALESCE(dispatched_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(20);
    }
}
