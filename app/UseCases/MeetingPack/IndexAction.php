<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 面談パック管理一覧を検索条件付きで取得するユースケース。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, MeetingPack>
     */
    public function __invoke(?string $keyword, ?MeetingPackStatus $status, int $perPage = 20): LengthAwarePaginator
    {
        $query = MeetingPack::query();

        if ($keyword !== null && $keyword !== '') {
            $query->where('name', 'like', '%'.$keyword.'%');
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        return $query
            ->ordered()
            ->paginate($perPage)
            ->withQueryString();
    }
}
