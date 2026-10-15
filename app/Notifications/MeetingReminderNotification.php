<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminderNotification extends Notification
{
    public function __construct(
        private readonly Meeting $meeting,
        private readonly string $window,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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

        return (new MailMessage)
            ->subject('Certify LMS '.$data['title'])
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($data['message'])
            ->action('面談予約を確認する', $data['url'])
            ->salutation('Certify LMS 運営チーム');
    }
}
