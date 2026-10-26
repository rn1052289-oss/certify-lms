<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Concerns\QueuesNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AdminAnnouncementNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use QueuesNotification;

    public function __construct(
        private readonly Announcement $announcement,
        private readonly ?array $channels = null,
    ) {
        $this->afterCommit();
    }

    protected function deliveryMetadata(): array
    {
        return ['announcement_id' => $this->announcement->id];
    }

    public function via(object $notifiable): array
    {
        return $this->channels ?? ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => 'admin_announcement',
            'title' => $this->announcement->title,
            'message' => Str::limit($this->announcement->body, 100),
            'body' => $this->announcement->body,
            'url' => route('notifications.show', $this->id),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->announcement->title)
            ->greeting('Certify LMS をご利用の皆様へ');

        foreach (preg_split('/\R/u', $this->announcement->body) as $line) {
            $message->line($line);
        }

        return $message->action('お知らせを確認する', route('notifications.show', $this->id))
            ->salutation('Certify LMS 運営チーム');
    }
}
