<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AiChatConversation モデルのリレーション・Cast を検証する Unit テスト。
 * 4 リレーション (user / enrollment / section / messages) + 3 cast を網羅する。
 */
class AiChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relation_returns_owner(): void
    {
        $user = User::factory()->student()->create();

        $conversation = $this->createConversation(['user_id' => $user->id]);

        $this->assertTrue($conversation->user->is($user));
    }

    public function test_enrollment_and_section_relations_return_linked_records(): void
    {
        $enrollment = Enrollment::factory()->create();
        $section = Section::factory()->create();

        $conversation = $this->createConversation([
            'user_id' => $enrollment->user_id,
            'enrollment_id' => $enrollment->id,
            'section_id' => $section->id,
        ]);

        $this->assertTrue($conversation->enrollment->is($enrollment));
        $this->assertTrue($conversation->section->is($section));
    }

    public function test_enrollment_and_section_relations_return_null_for_general_consultation(): void
    {
        $conversation = $this->createConversation();

        $this->assertNull($conversation->enrollment);
        $this->assertNull($conversation->section);
    }

    public function test_messages_relation_returns_only_own_messages_in_created_order(): void
    {
        $conversation = $this->createConversation();
        $other = $this->createConversation();

        $first = $this->createMessage($conversation, AiChatMessageRole::User, '最初の質問');
        $second = $this->createMessage($conversation, AiChatMessageRole::Assistant, '最初の回答');
        $this->createMessage($other, AiChatMessageRole::User, '別の相談');

        $this->assertSame(
            [$first->id, $second->id],
            $conversation->messages->pluck('id')->all(),
        );
    }

    public function test_casts_convert_flag_and_timestamps(): void
    {
        $conversation = $this->createConversation([
            'title_manually_edited' => 1,
            'auto_title_attempted_at' => '2026-10-01 10:00:00',
            'last_message_at' => '2026-10-02 11:00:00',
        ])->fresh();

        $this->assertTrue($conversation->title_manually_edited);
        $this->assertInstanceOf(Carbon::class, $conversation->auto_title_attempted_at);
        $this->assertInstanceOf(Carbon::class, $conversation->last_message_at);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createConversation(array $attributes = []): AiChatConversation
    {
        return AiChatConversation::create(array_merge([
            'user_id' => $attributes['user_id'] ?? User::factory()->student()->create()->id,
        ], $attributes));
    }

    private function createMessage(AiChatConversation $conversation, AiChatMessageRole $role, string $content): AiChatMessage
    {
        return AiChatMessage::create([
            'ai_chat_conversation_id' => $conversation->id,
            'role' => $role,
            'status' => AiChatMessageStatus::Completed,
            'content' => $content,
        ]);
    }
}
