<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function targetCertification(): BelongsTo
    {
        return $this->belongsTo(Certification::class, 'target_certification_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
