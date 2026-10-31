<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatMessage モデルのリレーション・Cast・非表示属性を検証する Unit テスト。
 * 1 リレーション (conversation) + 2 cast (role / status) + hidden を網羅する。
 */
class AiChatMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_relation_returns_parent_conversation(): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => User::factory()->student()->create()->id,
        ]);

        $message = $this->createMessage($conversation);

        $this->assertTrue($message->conversation->is($conversation));
    }

    public function test_role_and_status_are_cast_to_enums(): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => User::factory()->student()->create()->id,
        ]);

        $message = $this->createMessage($conversation)->fresh();

        $this->assertSame(AiChatMessageRole::Assistant, $message->role);
        $this->assertSame(AiChatMessageStatus::Error, $message->status);
    }

    public function test_internal_details_are_hidden_from_serialization(): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => User::factory()->student()->create()->id,
        ]);

        $serialized = $this->createMessage($conversation)->fresh()->toArray();

        $this->assertSame('回答を作成できませんでした。', $serialized['content']);
        foreach (['model', 'input_tokens', 'output_tokens', 'response_time_ms', 'error_detail'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }
    }

    private function createMessage(AiChatConversation $conversation): AiChatMessage
    {
        return AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Error,
            'content' => '回答を作成できませんでした。',
            'model' => 'gemini-test',
            'input_tokens' => 10,
            'output_tokens' => 20,
            'response_time_ms' => 300,
            'error_detail' => 'timeout',
        ]);
    }
}
