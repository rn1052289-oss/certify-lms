<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnrollmentGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enrollment 単位の個人学習目標を表す Model。
 *
 * 達成状態は achieved_at の有無で表し、目標自体は物理削除する。
 *
 * 関連: Enrollment(親)
 * scope: displayOrder(未達成 → 期日が近い順 → 期日なし → 同条件は新しい順)
 */
class EnrollmentGoal extends Model
{
    /** @use HasFactory<EnrollmentGoalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'title',
        'description',
        'target_date',
        'achieved_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'achieved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @param Builder<EnrollmentGoal> $query
     *
     * @return Builder<EnrollmentGoal>
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN achieved_at IS NULL AND target_date IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN achieved_at IS NULL THEN target_date END')
            ->orderByDesc('created_at');
    }

    public function isAchieved(): bool
    {
        return $this->achieved_at !== null;
    }
}
