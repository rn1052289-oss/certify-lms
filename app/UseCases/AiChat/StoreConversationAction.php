<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChatContextService;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の AI 相談会話を作成するユースケース。
 *
 * 同じ教材 × 受講登録の会話が既にあれば新規作成せず、本人の最新の会話を返す(同時開始も受講生行のロックで直列化)。
 * 小窓からの開始では、会話の作成と最初の質問の受付を同じトランザクションで確定する(withMessage)。
 */
class StoreConversationAction
{
    public function __construct(
        private readonly AiChatContextService $context,
        private readonly SendMessageAction $send,
    ) {}

    /**
     * 会話を作成し、最初の質問を受け付けて AI の回答まで返す。
     *
     * 受付前に拒否された場合(503 / 429 など)は会話の作成ごと取り消し、空の会話を残さない。Gemini はコミット後に呼ぶ。
     *
     * @param array{section_id?: ?string, enrollment_id?: ?string, message: string} $data
     *
     * @return array{user_message: AiChatMessage, assistant_message: AiChatMessage, conversation: AiChatConversation, upstream_status: ?int}
     */
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

    /**
     * @param array{section_id?: ?string, enrollment_id?: ?string} $data
     */
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
