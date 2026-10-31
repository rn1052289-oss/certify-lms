<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 面談リマインダー(前日 / 1 時間前)の送信記録を表す Model。
 *
 * 同一の面談 × window(eve: 前日 / one_hour_before: 1 時間前) × 受信者は 1 件に限り(unique)、二重送信を防ぐ。
 * アプリ内通知(database_sent_at)とメール(mail_sent_at)の送信日時を別々に記録する。
 *
 * 主要 Action: `Meeting\SendReminderAction`
 */
class MeetingReminderDelivery extends Model
{
    use HasUlids;

    protected $fillable = [
        'meeting_id',
        'user_id',
        'window',
        'notification_id',
        'database_sent_at',
        'mail_sent_at',
    ];

    protected $casts = [
        'database_sent_at' => 'datetime',
        'mail_sent_at' => 'datetime',
    ];
}
