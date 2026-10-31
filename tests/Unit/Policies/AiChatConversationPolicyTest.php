<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\AiChatConversation;
use App\Models\User;
use App\Policies\AiChatConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AiChatConversationPolicy の作成・閲覧・更新・削除権限を検証する。
 * 機能が有効なときの受講中の受講生本人だけが操作できることを確認する。
 */
class AiChatConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai-chat.enabled' => true]);
    }

    public function test_create_is_allowed_only_for_in_progress_student(): void
    {
        $policy = app(AiChatConversationPolicy::class);

        $this->assertTrue($policy->create(User::factory()->student()->inProgress()->create()));
        $this->assertFalse($policy->create(User::factory()->student()->graduated()->create()));
        $this->assertFalse($policy->create(User::factory()->coach()->inProgress()->create()));
        $this->assertFalse($policy->create(User::factory()->admin()->create()));
    }

    public function test_create_is_denied_when_feature_is_disabled(): void
    {
        config(['ai-chat.enabled' => false]);

        $this->assertFalse(app(AiChatConversationPolicy::class)->create(User::factory()->student()->inProgress()->create()));
    }

    public function test_owner_can_view_update_and_delete_own_conversation(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::create(['user_id' => $owner->id]);

        $policy = app(AiChatConversationPolicy::class);

        $this->assertTrue($policy->view($owner, $conversation));
        $this->assertTrue($policy->update($owner, $conversation));
        $this->assertTrue($policy->delete($owner, $conversation));
    }

    public function test_other_users_cannot_view_update_or_delete_conversation(): void
    {
        $conversation = AiChatConversation::create([
            'user_id' => User::factory()->student()->inProgress()->create()->id,
        ]);
        $otherStudent = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $admin = User::factory()->admin()->create();

        $policy = app(AiChatConversationPolicy::class);

        foreach ([$otherStudent, $coach, $admin] as $user) {
            $this->assertFalse($policy->view($user, $conversation));
            $this->assertFalse($policy->update($user, $conversation));
            $this->assertFalse($policy->delete($user, $conversation));
        }
    }

    public function test_owner_loses_access_after_graduation(): void
    {
        $owner = User::factory()->student()->graduated()->create();
        $conversation = AiChatConversation::create(['user_id' => $owner->id]);

        $this->assertFalse(app(AiChatConversationPolicy::class)->view($owner, $conversation));
    }
}
