<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QaReply;
use App\Notifications\Concerns\QueuesNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * 質問掲示板に回答が投稿された際の通知。
 *
 * 質問者へアプリ内通知とメールを送信する。
 * 投稿者本人への通知除外は呼び出し元で行う。
 */
class QaReplyReceivedNotification extends Notification implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use QueuesNotification;

    public function __construct(
        private readonly QaReply $reply,
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
        $authorName = $this->reply->user?->name ?? '回答者';

        return [
            'notification_type' => 'qa_reply_received',
            'title' => '質問掲示板に回答がありました',
            'message' => $authorName.'さんがあなたの質問に回答しました。',
            'url' => route('qa-board.show', $this->reply->qa_thread_id),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $authorName = $this->reply->user?->name ?? '回答者';

        return (new MailMessage)
            ->subject('Certify LMS 質問掲示板への回答のお知らせ')
            ->greeting('Certify LMS をご利用の皆様へ')
            ->line($authorName.'さんがあなたの質問に回答しました。')
            ->action('回答を確認する', route('qa-board.show', $this->reply->qa_thread_id))
            ->salutation('Certify LMS 運営チーム');
    }
}
