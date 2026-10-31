<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * コーチ本人宛の面談一覧をフィルタ付きで取得するユースケース。
 *
 * コーチ本人の担当面談一覧を取得するユースケース。
 *
 * filter: upcoming(既定、次の面談が先頭になる昇順) / past / all(新しい順)。受講生・受講登録で絞り込める。
 */
final class IndexAsCoachAction
{
    /** @return array{meetings: LengthAwarePaginator, filter: string, studentFilter: ?string, enrollmentFilter: ?string} */
    public function __invoke(User $coach, string $filter = 'upcoming', ?string $studentId = null, ?string $enrollmentId = null): array
    {
        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        // upcoming: 次の面談を一番上に置く (昇順) / past + all: 直近の活動を一番上 (降順)
        $meetings = match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate(20),
            'all' => $query->orderByDesc('scheduled_at')->paginate(20),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate(20),
        };

        return [
            'meetings' => $meetings,
            'filter' => $filter,
            'studentFilter' => $studentId,
            'enrollmentFilter' => $enrollmentId,
        ];
    }
}
