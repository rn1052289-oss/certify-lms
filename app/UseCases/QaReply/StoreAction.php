<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Support\Facades\DB;

/**
 * 質問スレッドへの回答投稿ユースケース。
 *
 * 回答投稿権限と入力値の検証は StoreRequest で行う。
 * 回答の保存完了後、投稿者本人を除く有効な質問者へ通知する。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaThread $thread, User $author, array $validated): QaReply
    {
        return DB::transaction(function () use ($thread, $author, $validated) {
            $thread = QaThread::query()->lockForUpdate()->findOrFail($thread->id);

            $reply = $thread->replies()->create([
                'user_id' => $author->id,
                'body' => $validated['body'],
            ]);

            DB::afterCommit(function () use ($thread, $author, $reply): void {
                $questioner = $thread->user;

                if (
                    $questioner === null
                    || $questioner->id === $author->id
                    || $questioner->role !== UserRole::Student
                    || $questioner->status !== UserStatus::InProgress
                    || $questioner->trashed()
                ) {
                    return;
                }

                try {
                    $questioner->notify(new QaReplyReceivedNotification($reply));
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $reply;
        });
    }
}
