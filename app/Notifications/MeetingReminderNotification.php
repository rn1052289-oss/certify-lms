<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Notifications\Concerns\QueuesNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminderNotification extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use QueuesNotification;

    public function __construct(
        private readonly Meeting $meeting,
        private readonly string $window,
        private readonly ?string $deliveryId = null,
        private readonly ?array $channels = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channels ?? ['database', 'mail'];
    }

    protected function deliveryMetadata(): array
    {
        return ['reminder_delivery_id' => $this->deliveryId];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $meeting = $this->meeting->fresh();
        $role = $notifiable->id === $meeting?->student_id ? UserRole::Student : UserRole::Coach;

        return $meeting !== null && $meeting->status === MeetingStatus::Reserved
            && $meeting->scheduled_at->gt(now())
            && $meeting->scheduled_at->lte(now()->addHours($this->window === 'eve' ? 24 : 1))
            && $notifiable->role === $role && $notifiable->status === UserStatus::InProgress;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'notification_type' => 'meeting_reminder',
            'title' => $this->window === 'eve' ? '面談の前日リマインダー' : '面談の1時間前リマインダー',
            'message' => $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i').'からの面談をご確認ください。',
            'url' => route('meetings.show', $this->meeting),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);
        // リマインダー専用設定でSMTP待機を制限し、他のメール配信設定は変えない。
        config(['mail.mailers.meeting_reminders' => array_replace(
            config('mail.mailers.'.config('mail.default'), []),
            ['timeout' => 30],
        )]);

        return (new MailMessage)
            ->mailer('meeting_reminders')
            ->subject('Certify LMS '.$data['title'])
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($data['message'])
            ->action('面談予約を確認する', $data['url'])
            ->salutation('Certify LMS 運営チーム');
    }
}
