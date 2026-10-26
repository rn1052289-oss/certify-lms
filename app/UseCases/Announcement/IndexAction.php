<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IndexAction
{
    public function __invoke(): LengthAwarePaginator
    {
        return Announcement::query()
            ->with(['targetCertification', 'targetUser', 'createdBy'])
            ->orderByRaw('COALESCE(dispatched_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(20);
    }
}
