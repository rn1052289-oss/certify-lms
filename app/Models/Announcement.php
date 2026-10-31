<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理者が配信するお知らせを表す Model。
 *
 * target_type で配信対象(全受講生 / 資格の受講生 / 特定の受講生)を決め、対象に応じて
 * target_certification_id / target_user_id を持つ。配信結果は dispatched_count / failed_count / failed_user_ids に記録する。
 *
 * 関連: Certification(対象資格) / User(targetUser / createdBy、退会後も参照可)
 * 主要 Action: `Announcement\StoreAction`
 */
class Announcement extends Model
{
    use HasUlids;

    protected $fillable = [
        'title', 'body', 'target_type', 'target_certification_id',
        'target_user_id', 'created_by_user_id', 'dispatched_count', 'dispatched_at',
        'failed_count', 'failed_user_ids',
    ];

    protected $casts = [
        'target_type' => AnnouncementTargetType::class,
        'dispatched_count' => 'integer',
        'dispatched_at' => 'datetime',
        'failed_count' => 'integer',
        'failed_user_ids' => 'array',
    ];

    /**
     * @return BelongsTo<Certification, $this>
     */
    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'target_certification_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
