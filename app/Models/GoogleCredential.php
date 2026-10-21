<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** コーチ本人の primary カレンダー接続。トークンはレスポンスへ含めない。 */
class GoogleCredential extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'access_token', 'refresh_token', 'expires_at', 'calendar_id', 'connected_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['expires_at' => 'datetime', 'connected_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
