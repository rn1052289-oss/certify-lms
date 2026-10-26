<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use App\Notifications\Concerns\QueuesNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 面談がキャンセルされた際の通知。
 *
 * キャンセルした本人を除き、相手側へ
 * アプリ内通知とメールを送信する。
 * 通知先の選定は呼び出し元で行う。
 */
class MeetingCanceledNotification extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use QueuesNotification;

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
            'notification_type' => 'meeting_canceled',
            'title' => '面談がキャンセルされました',
            'message' => $scheduledAt.'からの面談がキャンセルされました。',
            'url' => route('meetings.show', $this->meeting),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $scheduledAt = $this->meeting->scheduled_at->translatedFormat('Y年n月j日 (D) H:i');

        return (new MailMessage)
            ->subject('Certify LMS 面談キャンセルのお知らせ')
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($scheduledAt.'からの面談がキャンセルされました。')
            ->action('面談の詳細を確認する', route('meetings.show', $this->meeting))
            ->salutation('Certify LMS 運営チーム');
    }
}
