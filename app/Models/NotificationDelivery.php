<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 通知の受信者・チャネルごとの投入と配信結果を記録する。 */
class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
