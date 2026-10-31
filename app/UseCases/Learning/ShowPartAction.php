<?php

declare(strict_types=1);

namespace App\UseCases\Learning;

use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Models\Part;
use App\Models\User;
use App\Services\LearningProgressService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /learning/parts/{part} (3 階層目、Chapter 一覧) のデータを準備する Action。
 *
 * 公開済 Chapter 一覧 + Part の Published 確認 (非公開なら 404) に加え、
 * Service が算出した各 Chapter の Section 総数・読了数・完了判定・率を Blade に渡す。
 * 受講生が当該資格に未登録の場合は完了数 0 として扱う。
 */
final class ShowPartAction
{
    public function __construct(
        private readonly LearningProgressService $progressService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Part $part, User $student): array
    {
        $part->loadMissing('certification');

        if (
            $part->status !== ContentStatus::Published
            || $part->certification->status !== CertificationStatus::Published
        ) {
            throw new NotFoundHttpException;
        }

        $enrollment = $student->enrollments()
            ->where('certification_id', $part->certification_id)
            ->first();

        return [
            'part' => $part->load('certification'),
            ...$this->progressService->summarizeChapters($part, $enrollment),
        ];
    }
}
