<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** この LMS が登録した予定だけを取消時に削除するための対応表。 */
/**
 * 面談と、Google Calendar に作成した予定の対応を表す Model。
 *
 * 1 面談 = 1 件。面談の取消時に calendar_id / event_id で予定を削除する。
 * 連携を解除すると google_credential_id は NULL になり、作成済みの予定は Google 側に残る。
 *
 * 関連: GoogleCredential(予定を作成した連携情報)
 */
class MeetingGoogleEvent extends Model
{
    use HasUlids;

    protected $fillable = ['meeting_id', 'google_credential_id', 'calendar_id', 'event_id'];

    /**
     * @return BelongsTo<GoogleCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(GoogleCredential::class, 'google_credential_id');
    }
}
