<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談予約が確定した際の通知。
 *
 * 予約した受講生を除き、担当コーチへ
 * アプリ内通知とメールを送信する。
 * 通知先の選定は呼び出し元で行う。
 */
class MeetingReservedNotification extends Notification
{
    public function __construct(
        private readonly Meeting $meeting,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        $scheduledAt = $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i');

        return [
            'notification_type' => 'meeting_reserved',
            'title' => '面談が予約されました',
            'message' => $scheduledAt.'からの面談が予約されました。',
            'url' => route('meetings.show', $this->meeting),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scheduledAt = $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i');

        return (new MailMessage)
            ->subject('Certify LMS 面談予約のお知らせ')
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($scheduledAt.'からの面談が予約されました。')
            ->action('面談予約を確認する', route('meetings.show', $this->meeting))
            ->salutation('Certify LMS 運営チーム');
    }
}
