<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板の回答(QaReply)に対する認可ポリシー。
 *
 * - create: 受講中のstudent / coachが閲覧可能な質問に回答できる
 * - update: 投稿者本人のみ（質問へのアクセス権も必要）
 * - delete: adminは全件、student / coachは投稿者本人のみ
 */
class QaReplyPolicy
{
    public function __construct(private readonly QaThreadPolicy $qaThreadPolicy) {}

    public function create(User $user, QaThread $thread): bool
    {
        return in_array($user->role, [UserRole::Student, UserRole::Coach], true)
            && $this->qaThreadPolicy->view($user, $thread);
    }

    public function update(User $user, QaReply $reply): bool
    {
        $thread = $reply->qaThread;

        return $thread !== null
            && $reply->user_id === $user->id
            && $this->create($user, $thread);
    }

    public function delete(User $user, QaReply $reply): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $this->update($user, $reply);
    }
}
