<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AI 相談会話 (AiChatConversation) リソースに対する認可ポリシー。
 *
 * - create: AI 相談が有効(config `ai-chat.enabled`)かつ受講中の受講生のみ
 * - view / update / delete: create の条件を満たす本人の会話のみ(コーチ・admin も他人の会話は不可)
 */
class AiChatConversationPolicy
{
    public function create(User $user): bool
    {
        return (bool) config('ai-chat.enabled')
            && $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    public function view(User $user, AiChatConversation $conversation): bool
    {
        return $this->create($user) && $conversation->user_id === $user->id;
    }

    public function update(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, AiChatConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
