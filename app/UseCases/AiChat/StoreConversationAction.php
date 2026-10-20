<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChatContextService;
use Illuminate\Support\Facades\DB;

class StoreConversationAction
{
    public function __construct(
        private readonly AiChatContextService $context,
        private readonly SendMessageAction $send,
    ) {}

    /** @return array{user_message: AiChatMessage, assistant_message: AiChatMessage, conversation: AiChatConversation, upstream_status: ?int} */
    public function withMessage(User $user, array $data): array
    {
        [$conversation, $prepared] = DB::transaction(function () use ($user, $data): array {
            $conversation = $this($user, $data);
            $prepared = $this->send->prepare($user, $conversation, $data['message']);

            return [$conversation, $prepared];
        });

        // 受付前の失敗は会話作成ごと戻し、外部APIはコミット後に呼ぶ。
        return $this->send->complete($conversation, $prepared);
    }

    public function __invoke(User $user, array $data): AiChatConversation
    {
        return DB::transaction(function () use ($user, $data): AiChatConversation {
            // 同じ教材から同時に開始しても、本人の最新会話を再利用する。
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $context = $this->context->resolve($user, $data['section_id'] ?? null, $data['enrollment_id'] ?? null);
            if ($context['section'] !== null) {
                $existing = $user->aiChatConversations()->where('section_id', $context['section']->id)
                    ->where('enrollment_id', $context['enrollment']->id)
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
