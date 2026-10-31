<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 通知の受信者・チャネルごとの投入と配信結果を記録する。 */
/**
 * 通知 1 件を受信者 × チャネル(database / mail)ごとに配信した記録を表す Model。
 *
 * キュー投入(queued_at)・送信完了(sent_at)・失敗(failed_at)の日時を持ち、同一の通知 × 受信者 × チャネルは 1 件に限る(unique)。
 * お知らせの配信結果の集計と、失敗分の再投入に使う。
 *
 * 主要 Service: `NotificationDeliveryService`
 */
class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
