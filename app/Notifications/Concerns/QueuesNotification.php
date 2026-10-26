<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Queue\Middleware\DeliverNotificationOnce;
use App\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Throwable;

/** 通知の既存チャネルを維持して、コミット後の配信・再試行・結果記録を共通化する。 */
trait QueuesNotification
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    private ?int $queuedDeliveryId = null;

    private ?string $queuedAnnouncementId = null;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function viaQueues(): array
    {
        return ['database' => 'notifications', 'mail' => 'notifications'];
    }

    public function middleware(User $notifiable, string $channel): array
    {
        $this->queuedAnnouncementId = $this->deliveryMetadata()['announcement_id'] ?? null;
        $this->queuedDeliveryId = app(NotificationDeliveryService::class)
            ->plan($this->id, $notifiable, $channel, $this->deliveryMetadata());

        return [new DeliverNotificationOnce($this->queuedDeliveryId, $this->queuedAnnouncementId)];
    }

    public function failed(Throwable $exception): void
    {
        if ($this->queuedDeliveryId !== null) {
            app(NotificationDeliveryService::class)->failed($this->queuedDeliveryId, $this->queuedAnnouncementId);
        }
    }

    protected function deliveryMetadata(): array
    {
        return [];
    }
}
