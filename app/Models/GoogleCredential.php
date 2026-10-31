<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** コーチ本人の primary カレンダー接続。トークンはレスポンスへ含めない。 */
/**
 * コーチ本人の Google Calendar 連携情報(OAuth トークン)を表す Model。
 *
 * 1 コーチ = 1 件。access_token / refresh_token は画面・JSON に出さない($hidden)。
 *
 * 関連: User(連携したコーチ)
 * 主要 Service: `GoogleCalendarService` / `GoogleCalendarClient`
 */
class GoogleCredential extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'access_token', 'refresh_token', 'expires_at', 'calendar_id', 'connected_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['expires_at' => 'datetime', 'connected_at' => 'datetime'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
