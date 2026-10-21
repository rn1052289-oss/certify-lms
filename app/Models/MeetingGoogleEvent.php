<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** この LMS が登録した予定だけを取消時に削除するための対応表。 */
class MeetingGoogleEvent extends Model
{
    use HasUlids;

    protected $fillable = ['meeting_id', 'google_credential_id', 'calendar_id', 'event_id'];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(GoogleCredential::class, 'google_credential_id');
    }
}
