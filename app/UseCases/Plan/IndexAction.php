<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * プラン管理一覧を検索条件付きで取得するユースケース。
 */
final class IndexAction
{
    /**
     * @return LengthAwarePaginator<int, Plan>
     */
    public function __invoke(?string $keyword, ?PlanStatus $status, int $perPage = 20): LengthAwarePaginator
    {
        $query = Plan::query()
            ->withCount('users');

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
