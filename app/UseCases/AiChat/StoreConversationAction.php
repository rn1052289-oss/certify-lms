<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Services\AiChatContextService;
use Illuminate\Support\Facades\DB;

class StoreConversationAction
{
    public function __construct(private readonly AiChatContextService $context) {}

    public function __invoke(User $user, array $data): AiChatConversation
    {
        return DB::transaction(function () use ($user, $data): AiChatConversation {
            // 同じ教材から同時に開始しても、本人の最新会話を再利用する。
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $context = $this->context->resolve($user, $data['section_id'] ?? null, $data['enrollment_id'] ?? null);
            if ($context['section'] !== null) {
                $existing = $user->aiChatConversations()->where('section_id', $context['section']->id)
                    ->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            return $user->aiChatConversations()->create([
                'enrollment_id' => $context['enrollment']?->id,
                'section_id' => $context['section']?->id,
                'title' => '新しい相談',
                'last_message_at' => now(),
            ]);
        });
    }
}
