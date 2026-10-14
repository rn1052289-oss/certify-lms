<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 質問掲示板の一覧データを取得するユースケース。
 *
 * - student: 公開済み資格の質問
 * - coach: 公開済みかつ担当資格の質問
 * - admin: 公開状態に関係なく全資格の質問
 */
final class IndexAction
{
    /**
     * @param array{certification_id?: string|null, status?: string|null, keyword?: string|null} $filters
     *
     * @return array{threads: LengthAwarePaginator, certifications: Collection<int, Certification>}
     */
    public function __invoke(User $viewer, array $filters): array
    {
        $certifications = Certification::query()->orderBy('name');
        $threads = QaThread::query()
            ->with(['certification', 'user'])
            ->withCount('replies');

        if ($viewer->role === UserRole::Student) {
            $certifications->published();
            $threads->whereHas('certification', fn ($query) => $query->published());
        } elseif ($viewer->role === UserRole::Coach) {
            $certifications->published()->assignedTo($viewer);
            $threads->whereHas('certification', fn ($query) => $query->published()->assignedTo($viewer));
        }

        $threads
            ->when(
                $filters['certification_id'] ?? null,
                fn ($query, string $id) => $query->where('certification_id', $id),
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->when(
                $filters['keyword'] ?? null,
                fn ($query, string $keyword) => $query->where('body', 'LIKE', '%'.$keyword.'%'),
            );

        return [
            'threads' => $threads
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'certifications' => $certifications->get(),
        ];
    }
}
