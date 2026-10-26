<?php

declare(strict_types=1);

namespace App\Queue\Middleware;

use App\Models\Announcement;
use App\Models\NotificationDelivery;
use App\Services\NotificationDeliveryService;
use Closure;
use Illuminate\Support\Facades\DB;

/** 同じ通知・受信者・チャネルのジョブが再投入されても、成功済みの配信を繰り返さない。 */
final class DeliverNotificationOnce
{
    public function __construct(
        private readonly int $deliveryId,
        private readonly ?string $announcementId = null,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        return DB::transaction(function () use ($job, $next): mixed {
            // 同じお知らせの結果更新を直列化し、集計前の古いスナップショットを避ける。
            if ($this->announcementId !== null) {
                Announcement::query()->lockForUpdate()->findOrFail($this->announcementId);
            }
            $delivery = NotificationDelivery::query()->lockForUpdate()->find($this->deliveryId);
            if ($delivery === null || $delivery->sent_at !== null) {
                return null;
            }

            $recipient = $job->notifiables->first();
            if (method_exists($job->notification, 'shouldSend')
                && ! $job->notification->shouldSend($recipient, $delivery->channel)) {
                return null;
            }

            $result = $next($job);
            app(NotificationDeliveryService::class)->sent($delivery);

            return $result;
        });
    }
}
