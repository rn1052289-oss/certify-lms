<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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
