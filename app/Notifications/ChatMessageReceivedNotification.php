<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ChatMessage;
use App\Notifications\Concerns\QueuesNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 新しいチャットメッセージを受信したユーザーへの通知。
 *
 * アプリ内通知とメールの両方で送信する。
 * 通知先の選定は呼び出し元で行う。
 */
class ChatMessageReceivedNotification extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use QueuesNotification;

    public function __construct(
        private readonly ChatMessage $message,
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
        $senderName = $this->message->sender?->name ?? '送信者';

        return [
            'notification_type' => 'chat_message_received',
            'title' => 'チャットに新着メッセージがあります',
            'message' => $senderName.'さんから新しいメッセージが届きました。',
            'url' => route('chat.show', $this->message->chat_room_id),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $senderName = $this->message->sender?->name ?? '送信者';

        return (new MailMessage)
            ->subject('Certify LMS チャットメッセージ受信のお知らせ')
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($senderName.'さんから新しいチャットメッセージが届きました。')
            ->action('チャットを確認する', route('chat.show', $this->message->chat_room_id))
            ->salutation('Certify LMS 運営チーム');
    }
}
