<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enrollment 集計を提供する Service。admin ダッシュボード KPI で利用される。
 *
 * 全体 KPI(adminKpi)と資格別修了率(completionRateByCertification)は全 enrollment を走査する重い集計。
 *
 * 集計対象は SoftDelete 除外。paused 集計は採用しない(3 値モデル)。
 * 受講生ダッシュボードの Action / Controller テストで Mockery 経由 mock するため `final` は付けない。
 */
class EnrollmentStatsService
{
    /**
     * 全体 KPI(learning / passed / failed 件数 + 資格別内訳)を返す。
     *
     * @return array{learning_count: int, passed_count: int, failed_count: int, total: int, by_certification: array<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int}>}
     */
    public function adminKpi(): array
    {
        return $this->cached(config('dashboard.admin_kpi_cache_key'), fn (): array => $this->calculateAdminKpi());
    }

    private function calculateAdminKpi(): array
    {
        $counts = DB::table('enrollments')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $learning = (int) ($counts[EnrollmentStatus::Learning->value] ?? 0);
        $passed = (int) ($counts[EnrollmentStatus::Passed->value] ?? 0);
        $failed = (int) ($counts[EnrollmentStatus::Failed->value] ?? 0);

        return [
            'learning_count' => $learning,
            'passed_count' => $passed,
            'failed_count' => $failed,
            'total' => $learning + $passed + $failed,
            'by_certification' => $this->byCertification(),
        ];
    }

    /**
     * 資格別の受講生数(status 別の内訳付き)。
     *
     * @return array<string, array{learning: int, passed: int, failed: int}> キーは certification_id
     */
    public function perCertification(): array
    {
        $rows = DB::table('enrollments')
            ->whereNull('deleted_at')
            ->selectRaw('certification_id, status, COUNT(*) as cnt')
            ->groupBy('certification_id', 'status')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $certId = (string) $row->certification_id;
            $result[$certId] ??= ['learning' => 0, 'passed' => 0, 'failed' => 0];
            $result[$certId][(string) $row->status] = (int) $row->cnt;
        }

        return $result;
    }

    /**
     * 資格別の修了率(passed / 全件)を Collection で返す。
     * 0 件の資格は除外する(0 % 表示は意味がないため)。
     * 一覧は受講生数(total)の多い順、上位 10 件まで。
     *
     * @return Collection<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int, completion_rate: float}>
     */
    public function completionRateByCertification(): Collection
    {
        return $this->cached(config('dashboard.admin_completion_rate_cache_key'), fn (): Collection => $this->calculateCompletionRates());
    }

    private function calculateCompletionRates(): Collection
    {
        return collect($this->byCertification())
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->map(function (array $row): array {
                $row['completion_rate'] = round($row['passed'] / $row['total'], 4);

                return $row;
            })
            ->sortByDesc('total')
            ->values();
    }

    /** 状態変更が確定してから両集計を無効化する。rollback 時は既存キャッシュを保持する。 */
    public function invalidateAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            // 世代を先に切り替え、変更前に開始した集計の遅延書込も次回読取で除外する。
            $this->cacheOperation(fn () => Cache::forever('enrollment-stats:admin:generation', (string) Str::uuid()));
            $this->cacheOperation(fn () => Cache::forget(config('dashboard.admin_kpi_cache_key')));
            $this->cacheOperation(fn () => Cache::forget(config('dashboard.admin_completion_rate_cache_key')));
        });
    }

    private function cached(string $key, Closure $calculate): mixed
    {
        // 未コミットの集計を共有キャッシュへ保存しない。
        $ttl = (int) config('dashboard.admin_cache_ttl');
        if (DB::transactionLevel() > 0 || $ttl <= 0) {
            return $calculate();
        }

        $entry = $this->cacheOperation(fn () => [
            Cache::get('enrollment-stats:admin:generation', 'initial'),
            Cache::get($key),
        ]);
        if ($entry === null) {
            return $calculate();
        }

        [$generation, $cached] = $entry;
        if (is_array($cached) && ($cached['generation'] ?? null) === $generation) {
            return $cached['value'];
        }

        $value = $calculate();
        $this->cacheOperation(fn () => Cache::put($key, ['generation' => $generation, 'value' => $value], $ttl));

        return $value;
    }

    /** キャッシュ障害を記録し、集計や確定済みの業務処理には伝播させない。 */
    private function cacheOperation(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * 資格別の集計を「資格 ID + 名前 + status 別件数」の配列で返す内部ヘルパー。
     *
     * @return array<int, array{certification_id: string, certification_name: string, learning: int, passed: int, failed: int, total: int}>
     */
    private function byCertification(): array
    {
        $rows = DB::table('enrollments')
            ->join('certifications', 'enrollments.certification_id', '=', 'certifications.id')
            ->whereNull('enrollments.deleted_at')
            ->selectRaw('enrollments.certification_id, certifications.name as certification_name, enrollments.status, COUNT(*) as cnt')
            ->groupBy('enrollments.certification_id', 'certifications.name', 'enrollments.status')
            ->get();

        $byCertification = [];
        foreach ($rows as $row) {
            $certId = (string) $row->certification_id;
            $byCertification[$certId] ??= [
                'certification_id' => $certId,
                'certification_name' => (string) $row->certification_name,
                'learning' => 0,
                'passed' => 0,
                'failed' => 0,
                'total' => 0,
            ];
            $byCertification[$certId][(string) $row->status] = (int) $row->cnt;
            $byCertification[$certId]['total'] += (int) $row->cnt;
        }

        $list = array_values($byCertification);
        usort($list, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $list;
    }
}
