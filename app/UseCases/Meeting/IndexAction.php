<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 受講生本人の面談一覧と残面談回数を取得するユースケース。
 *
 * 受講生本人の面談一覧と残面談回数を取得するユースケース。
 *
 * filter: upcoming(既定) / past / all。いずれも予約日時の新しい順に 20 件ずつ返す。
 */
final class IndexAction
{
    public function __construct(
        private readonly MeetingQuotaService $meetingQuota,
    ) {}

    /** @return array{meetings: LengthAwarePaginator, filter: string, meetingsRemaining: int} */
    public function __invoke(User $student, string $filter = 'upcoming'): array
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate(20),
            'all' => $query->paginate(20),
            default => $query->upcoming()->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'filter' => $filter,
            'meetingsRemaining' => $this->meetingQuota->remaining($student),
        ];
    }
}
