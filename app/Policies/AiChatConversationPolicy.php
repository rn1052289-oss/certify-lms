<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiChatConversation;
use App\Models\User;

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
