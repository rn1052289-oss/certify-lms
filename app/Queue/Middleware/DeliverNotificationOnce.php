<?php

declare(strict_types=1);

namespace App\Queue\Middleware;

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
        $result = DB::transaction(function () use ($job, $next): mixed {
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
        app(NotificationDeliveryService::class)->refreshAnnouncement($this->announcementId);

        return $result;
    }
}
