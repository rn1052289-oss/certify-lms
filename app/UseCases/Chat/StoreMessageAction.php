<?php

declare(strict_types=1);

namespace App\UseCases\Chat;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\ChatMessageSent;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use Illuminate\Support\Facades\DB;

/**
 * チャットメッセージの保存・送信者の既読時刻更新・通知を行う Action。
 *
 * - ChatMessage 作成時に ChatRoom.last_message_at が更新される
 * - 送信者自身の ChatMember.last_read_at を更新する
 * - DB コミット後に既存 Broadcast と受信者への通知を実行する
 * - 通知先は現在の受講生・担当コーチのうち、有効なチャット参加者のみ
 */
final class StoreMessageAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(User $sender, ChatRoom $room, array $validated): ChatMessage
    {
        return DB::transaction(function () use ($sender, $room, $validated) {
            $message = ChatMessage::create([
                'chat_room_id' => $room->id,
                'sender_user_id' => $sender->id,
                'body' => $validated['body'],
            ]);

            ChatMember::query()
                ->where('chat_room_id', $room->id)
                ->where('user_id', $sender->id)
                ->update(['last_read_at' => now()]);

            DB::afterCommit(function () use ($message, $room, $sender): void {
                broadcast(new ChatMessageSent($message->load('sender')))->toOthers();

                $enrollment = $room->enrollment()
                    ->with(['user', 'certification.coaches'])
                    ->firstOrFail();

                $memberIds = $room->members()->pluck('user_id')->all();

                $recipients = $enrollment->certification->coaches
                    ->filter(fn (User $coach): bool => $coach->role === UserRole::Coach);

                $student = $enrollment->user;

                if ($student !== null && $student->role === UserRole::Student) {
                    $recipients->push($student);
                }

                foreach ($recipients as $recipient) {
                    if (
                        $recipient->id === $sender->id
                        || $recipient->status !== UserStatus::InProgress
                        || ! in_array($recipient->id, $memberIds, true)
                    ) {
                        continue;
                    }

                    $recipient->notify(new ChatMessageReceivedNotification($message));
                }
            });

            return $message;
        });
    }
}
