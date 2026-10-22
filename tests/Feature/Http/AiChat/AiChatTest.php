<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\CertificationStatus;
use App\Enums\ContentStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\Content\ContentNotDeletableException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\Section\DestroyAction;
use Database\Seeders\AiChatVerificationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'test-key', 'ai-chat.gemini.model' => 'verification-model', 'ai-chat.auto_title' => false]);
        Http::preventStrayRequests();
    }

    private function conversation(?User $user = null): AiChatConversation
    {
        $user ??= User::factory()->student()->create();

        return $user->aiChatConversations()->create(['title' => '新しい相談', 'last_message_at' => now()]);
    }

    private function response(string $content = '学習の回答です。'): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => $content]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 25],
        ];
    }

    private function sectionFor(User $student): array
    {
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $section = Section::factory()->published()->forChapter($chapter)->withBody('公開教材本文')->create();

        return [$enrollment, $section];
    }

    public function test_owner_can_create_rename_read_and_delete_conversation(): void
    {
        $student = User::factory()->student()->create();
        $this->actingAs($student)->get('/ai-chat')->assertOk()->assertViewIs('ai-chat.empty-state');
        $id = $this->postJson('/ai-chat/conversations', ['source' => 'widget'])->assertCreated()->json('conversation.id');
        $this->patchJson('/ai-chat/conversations/'.$id, ['title' => '本人のタイトル'])->assertOk();
        $this->getJson('/ai-chat/conversations/'.$id)->assertOk()->assertJsonPath('conversation.title', '本人のタイトル');
        $this->get('/ai-chat')->assertRedirect('/ai-chat/conversations/'.$id);
        $this->get('/ai-chat/conversations/'.$id)->assertOk()->assertViewIs('ai-chat.show');
        $this->deleteJson('/ai-chat/conversations/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('ai_chat_conversations', ['id' => $id]);
        Http::assertNothingSent();
    }

    public function test_history_json_does_not_expose_material_or_enrollment_relations_after_access_changes(): void
    {
        foreach (['published', 'section', 'chapter', 'part', 'certification', 'enrollment'] as $changed) {
            $student = User::factory()->student()->create();
            [$enrollment, $section] = $this->sectionFor($student);
            $conversation = $this->conversation($student);
            $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
            $conversation->messages()->create([
                'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed,
                'content' => '本人の保存済み相談',
            ]);
            $section->update(['body' => '保存済み相談には含まれない教材の最新本文']);
            if (in_array($changed, ['section', 'chapter', 'part'], true)) {
                $model = match ($changed) {
                    'section' => $section, 'chapter' => $section->chapter, 'part' => $section->chapter->part,
                };
                $model->update(['status' => ContentStatus::Draft]);
            } elseif ($changed === 'certification') {
                $enrollment->certification->update(['status' => CertificationStatus::Archived]);
            } elseif ($changed === 'enrollment') {
                $enrollment->update(['status' => EnrollmentStatus::Failed]);
            }

            $response = $this->actingAs($student)->getJson('/ai-chat/conversations/'.$conversation->id)
                ->assertOk()->assertJsonPath('conversation.id', $conversation->id)
                ->assertJsonPath('conversation.enrollment_id', $enrollment->id)
                ->assertJsonPath('conversation.section_id', $section->id)
                ->assertJsonPath('messages.0.content', '本人の保存済み相談')
                ->assertJsonMissingPath('conversation.section')
                ->assertJsonMissingPath('conversation.enrollment')
                ->assertJsonMissingPath('conversation.messages')
                ->assertDontSee('保存済み相談には含まれない教材の最新本文');
            $this->assertSame(['id', 'title', 'enrollment_id', 'section_id'], array_keys($response->json('conversation')));
        }
        Http::assertNothingSent();
    }

    public function test_html_conversation_keeps_context_relations_for_existing_view(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $conversation = $this->conversation($student);
        $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
        $this->actingAs($student)->get('/ai-chat/conversations/'.$conversation->id)
            ->assertOk()->assertViewIs('ai-chat.show')
            ->assertViewHas('conversation', fn (AiChatConversation $shown) => $shown->relationLoaded('messages')
                && $shown->relationLoaded('section') && $shown->section->id === $section->id
                && $shown->relationLoaded('enrollment') && $shown->enrollment->relationLoaded('certification'));
        Http::assertNothingSent();
    }

    public function test_admin_can_delete_draft_section_without_deleting_ai_conversation_or_messages(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $conversation = $this->conversation($student);
        $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
        foreach ([AiChatMessageRole::User, AiChatMessageRole::Assistant] as $role) {
            $conversation->messages()->create([
                'role' => $role, 'status' => AiChatMessageStatus::Completed,
                'content' => '削除後も残す'.$role->value,
            ]);
        }
        DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 1]);
        $section->update(['status' => ContentStatus::Draft]);
        $this->actingAs(User::factory()->admin()->create())->delete(route('admin.sections.destroy', $section))
            ->assertRedirect(route('admin.chapters.show', $section->chapter_id));
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id, 'enrollment_id' => $enrollment->id, 'section_id' => null]);
        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $conversation->id, 'content' => '削除後も残すuser']);
        $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $conversation->id, 'content' => '削除後も残すassistant']);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
        $this->actingAs($student)->getJson('/ai-chat/conversations/'.$conversation->id)
            ->assertOk()->assertJsonPath('conversation.section_id', null)->assertJsonCount(2, 'messages');
        Http::assertNothingSent();
    }

    public function test_published_section_with_ai_conversation_remains_undeletable(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $conversation = $this->conversation($student);
        $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
        try {
            app(DestroyAction::class)($section);
            $this->fail('公開中の教材は削除できない。');
        } catch (ContentNotDeletableException) {
            $this->assertDatabaseHas('sections', ['id' => $section->id]);
            $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id, 'section_id' => $section->id]);
        }
        Http::assertNothingSent();
    }

    public function test_full_screen_and_widget_hide_operational_metadata_but_keep_answer_and_internal_values(): void
    {
        config(['ai-chat.gemini.model' => 'internal-model-display-check']);
        $conversation = $this->conversation();
        $message = $conversation->messages()->create([
            'role' => AiChatMessageRole::Assistant,
            'status' => AiChatMessageStatus::Completed,
            'content' => '表示する学習の回答',
            'model' => 'internal-model-display-check',
            'response_time_ms' => 12345,
            'input_tokens' => 123456,
            'output_tokens' => 987654,
        ]);
        $this->actingAs($conversation->user)->get('/ai-chat/conversations/'.$conversation->id)
            ->assertOk()
            ->assertSee('表示する学習の回答')
            ->assertDontSee('internal-model-display-check')
            ->assertDontSee('987,654 tokens')
            ->assertDontSee('12.3 s');

        $widget = Blade::render('<x-ai-chat.floating-widget />');
        $this->assertStringNotContainsString('internal-model-display-check', $widget);
        $this->assertStringContainsString('全般相談', $widget);
        $this->assertDatabaseHas('ai_chat_messages', [
            'id' => $message->id,
            'model' => 'internal-model-display-check',
            'response_time_ms' => 12345,
            'input_tokens' => 123456,
            'output_tokens' => 987654,
        ]);
        Http::assertNothingSent();
    }

    public function test_feature_flag_hides_only_ai_navigation_when_disabled(): void
    {
        $student = User::factory()->student()->create();
        $this->actingAs($student)->get('/settings/profile')->assertOk()
            ->assertSee('href="'.route('ai-chat.index').'"', false);
        config(['ai-chat.enabled' => false]);
        $this->get('/settings/profile')->assertOk()
            ->assertDontSee('href="'.route('ai-chat.index').'"', false)
            ->assertSee('href="'.route('chat.index').'"', false);
        Http::assertNothingSent();
    }

    public function test_other_students_cannot_read_rename_delete_or_send(): void
    {
        $conversation = $this->conversation();
        $this->actingAs(User::factory()->student()->create());
        $url = '/ai-chat/conversations/'.$conversation->id;
        $this->getJson($url)->assertForbidden();
        $this->patchJson($url, ['title' => '変更'])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        $this->postJson($url.'/messages', ['content' => '質問'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_active_students_can_use_every_endpoint(): void
    {
        $conversation = $this->conversation();
        foreach ([User::factory()->admin()->create(), User::factory()->coach()->create(), User::factory()->student()->graduated()->create(), User::factory()->student()->withdrawn()->create(), User::factory()->student()->invited()->create()] as $user) {
            $this->actingAs($user);
            $url = '/ai-chat/conversations/'.$conversation->id;
            $this->getJson('/ai-chat')->assertForbidden();
            $this->postJson('/ai-chat/conversations', [])->assertForbidden();
            $this->getJson($url)->assertForbidden();
            $this->patchJson($url, ['title' => '変更'])->assertForbidden();
            $this->deleteJson($url)->assertForbidden();
            $this->postJson($url.'/messages', ['content' => '質問'])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_disabled_feature_rejects_all_endpoints(): void
    {
        $conversation = $this->conversation();
        config(['ai-chat.enabled' => false]);
        $this->actingAs($conversation->user);
        $url = '/ai-chat/conversations/'.$conversation->id;
        $this->getJson('/ai-chat')->assertNotFound();
        $this->postJson('/ai-chat/conversations', [])->assertNotFound();
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['title' => '変更'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->postJson($url.'/messages', ['content' => '質問'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_same_section_reuses_latest_own_conversation_and_rejects_foreign_context(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $old = $this->conversation($student);
        $old->update(['section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'created_at' => now()->subDay()]);
        $latest = $this->conversation($student);
        $latest->update(['section_id' => $section->id, 'enrollment_id' => $enrollment->id]);
        $this->actingAs($student)->postJson('/ai-chat/conversations', ['section_id' => $section->id])->assertOk()->assertJsonPath('conversation.id', $latest->id);
        $this->actingAs(User::factory()->student()->create())->postJson('/ai-chat/conversations', ['section_id' => $section->id])->assertNotFound();
        $this->postJson('/ai-chat/conversations', ['enrollment_id' => $enrollment->id])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_unpublished_section_or_parent_is_not_sent_to_gemini(): void
    {
        foreach (['section', 'chapter', 'part'] as $level) {
            $student = User::factory()->student()->create();
            [$enrollment, $section] = $this->sectionFor($student);
            $conversation = $this->conversation($student);
            $conversation->update(['section_id' => $section->id, 'enrollment_id' => $enrollment->id]);
            $model = match ($level) {
                'section' => $section, 'chapter' => $section->chapter, 'part' => $section->chapter->part
            };
            $model->update(['status' => ContentStatus::Draft]);
            $this->actingAs($student)->postJson('/ai-chat/conversations', ['section_id' => $section->id])->assertNotFound();
            $this->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '質問'])->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_response_history_and_context_are_saved_and_operational_metadata_is_private(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $conversation = $this->conversation($student);
        $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
        Http::fake(['*' => Http::response($this->response())]);
        $url = '/ai-chat/conversations/'.$conversation->id;
        $result = $this->actingAs($student)->postJson($url.'/messages', ['content' => '質問です'])->assertOk();
        $result->assertJsonPath('assistant_message.content', '学習の回答です。')->assertJsonMissingPath('assistant_message.model')->assertJsonMissingPath('assistant_message.output_tokens')->assertJsonMissingPath('assistant_message.response_time_ms');
        $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $conversation->id, 'model' => 'verification-model', 'input_tokens' => 50, 'output_tokens' => 25]);
        $this->postJson($url.'/messages', ['content' => '続きの質問'])->assertOk();
        Http::assertSent(fn ($request) => $request->header('x-goog-api-key') === ['test-key'] && str_contains($request->url(), '/models/verification-model:generateContent') && ! str_contains($request->url(), 'test-key') && str_contains($request['systemInstruction']['parts'][0]['text'], '公開教材本文') && count($request['contents']) === 3 && $request['contents'][0]['parts'][0]['text'] === '質問です' && $request['contents'][1]['role'] === 'model');
        $this->getJson($url)->assertJsonCount(4, 'messages')->assertJsonMissingPath('messages.1.model');
    }

    public function test_default_enrollment_is_used_for_general_context(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment] = $this->sectionFor($student);
        $student->update(['default_enrollment_id' => $enrollment->id]);
        $this->actingAs($student)->postJson('/ai-chat/conversations', [])->assertCreated()->assertJsonPath('conversation.enrollment_id', $enrollment->id);
    }

    public function test_message_and_title_length_validation(): void
    {
        $conversation = $this->conversation();
        $this->actingAs($conversation->user);
        $url = '/ai-chat/conversations/'.$conversation->id;
        $this->postJson($url.'/messages', ['content' => ''])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->postJson($url.'/messages', ['content' => str_repeat('あ', 2001)])->assertUnprocessable();
        $this->postJson('/ai-chat/conversations', ['message' => str_repeat('あ', 2001)])->assertUnprocessable();
        $this->patchJson($url, ['title' => str_repeat('あ', 101)])->assertUnprocessable();
        $this->patchJson($url, ['title' => str_repeat('あ', 100)])->assertOk();
        Http::fake(['*' => Http::response($this->response())]);
        $this->postJson($url.'/messages', ['content' => str_repeat('あ', 2000)])->assertOk();
    }

    public function test_failed_api_requests_keep_questions_and_count_toward_daily_limit_even_after_deletion(): void
    {
        $conversation = $this->conversation();
        $student = $conversation->user;
        Http::fake(['*' => Http::response(['error' => ['message' => 'private-upstream-detail']], 429)]);
        $this->actingAs($student);
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '失敗する質問'])->assertStatus(502)->assertJsonPath('upstream_status', 429)->assertDontSee('private-upstream-detail');
        }
        $this->assertDatabaseCount('ai_chat_messages', 40);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => '失敗する質問']);
        $this->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '21回目'])->assertTooManyRequests();
        $this->deleteJson('/ai-chat/conversations/'.$conversation->id)->assertNoContent();
        $new = $this->conversation($student);
        $this->postJson('/ai-chat/conversations/'.$new->id.'/messages', ['content' => '削除後'])->assertTooManyRequests();
        Http::assertSentCount(20);
        $this->travelTo(now()->addDay()->startOfDay());
        $this->postJson('/ai-chat/conversations/'.$new->id.'/messages', ['content' => '翌日'])->assertStatus(502);
        Http::assertSentCount(21);
    }

    public function test_timeout_server_errors_and_invalid_response_keep_questions_and_allow_retry(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 5) {
                throw new ConnectionException('private-url-or-key');
            }
            $status = [500, 502, 503, 504][$calls - 1] ?? 200;
            $body = $calls === 7 ? $this->response('再質問の回答') : [];

            return Http::response($body, $status);
        });
        foreach ([500, 502, 503, 504] as $status) {
            $conversation = $this->conversation();
            $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '保存する質問'])->assertStatus(502)->assertJsonPath('upstream_status', $status);
            $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $conversation->id, 'role' => 'user', 'content' => '保存する質問']);
        }
        $conversation = $this->conversation();
        $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => 'タイムアウト'])->assertStatus(502)->assertDontSee('private-url-or-key');
        $this->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '空の応答'])->assertStatus(502);
        $this->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '再質問'])->assertOk()->assertJsonPath('assistant_message.content', '再質問の回答');
    }

    public function test_one_time_answer_save_failure_does_not_leave_pending_and_allows_retry(): void
    {
        $conversation = $this->conversation();
        $failed = false;
        Http::fake(['*' => Http::response($this->response())]);
        // 一度だけ回答保存時に失敗させ、その後のDB書込みは復旧した状態を再現する。
        $dispatcher = clone AiChatMessage::getEventDispatcher();
        AiChatMessage::setEventDispatcher(clone $dispatcher);
        AiChatMessage::updating(function (AiChatMessage $message) use (&$failed): void {
            if (! $failed && $message->role === AiChatMessageRole::Assistant && $message->status === AiChatMessageStatus::Completed) {
                $failed = true;
                throw new QueryException('testing', '回答の保存', [], new \RuntimeException('private-sql-key-question'));
            }
        });
        try {
            $url = '/ai-chat/conversations/'.$conversation->id.'/messages';
            $this->actingAs($conversation->user)->postJson($url, ['content' => '保存される質問'])->assertStatus(502)->assertDontSee('private-sql-key-question');
            $this->assertTrue($failed);
            $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $conversation->id, 'role' => 'user', 'content' => '保存される質問']);
            $this->assertSame(0, $conversation->messages()->where('status', AiChatMessageStatus::Pending->value)->count());
            $this->assertSame(1, $conversation->messages()->where('status', AiChatMessageStatus::Error->value)->count());
            $this->postJson($url, ['content' => '再質問'])->assertOk()->assertJsonPath('assistant_message.status', 'completed');
        } finally {
            AiChatMessage::setEventDispatcher($dispatcher);
        }
    }

    public function test_missing_key_explains_unavailability_without_request_or_quota_use(): void
    {
        $conversation = $this->conversation();
        config(['ai-chat.gemini.api_key' => '']);
        $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '質問'])->assertStatus(503)->assertSee('API', false);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        Http::assertNothingSent();
    }

    public function test_first_success_generates_title_only_once_and_manual_edit_survives(): void
    {
        config(['ai-chat.auto_title' => true]);
        $conversation = $this->conversation();
        Http::fakeSequence()->push($this->response('最初の回答'))->push($this->response('AIの見出し'))->push($this->response('次の回答'));
        $url = '/ai-chat/conversations/'.$conversation->id;
        $this->actingAs($conversation->user)->postJson($url.'/messages', ['content' => '最初の質問'])->assertOk()->assertJsonPath('conversation.title', 'AIの見出し');
        $this->patchJson($url, ['title' => '自分の見出し'])->assertOk();
        $this->postJson($url.'/messages', ['content' => '次の質問'])->assertOk()->assertJsonPath('conversation.title', '自分の見出し');
        Http::assertSentCount(3);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $conversation->user_id, 'attempts' => 2]);
    }

    public function test_failed_answer_does_not_trigger_title_and_title_failure_does_not_break_answer(): void
    {
        config(['ai-chat.auto_title' => true]);
        $conversation = $this->conversation();
        Http::fakeSequence()->push([], 503)->push($this->response())->push([], 503)->push($this->response());
        $this->actingAs($conversation->user);
        $url = '/ai-chat/conversations/'.$conversation->id.'/messages';
        $this->postJson($url, ['content' => '失敗'])->assertStatus(502);
        $this->postJson($url, ['content' => '成功'])->assertOk()->assertJsonPath('assistant_message.status', 'completed');
        $this->postJson($url, ['content' => '次も成功'])->assertOk();
        Http::assertSentCount(4);
    }

    public function test_title_edited_while_title_api_waits_is_not_overwritten(): void
    {
        config(['ai-chat.auto_title' => true]);
        $conversation = $this->conversation();
        $calls = 0;
        Http::fake(function () use ($conversation, &$calls) {
            $calls++;
            if ($calls === 2) {
                $conversation->update(['title' => '応答中に本人が変更', 'title_manually_edited' => true]);
            }

            return Http::response($this->response($calls === 1 ? '回答' : '自動のタイトル'));
        });
        $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '質問'])->assertOk()->assertJsonPath('conversation.title', '応答中に本人が変更');
    }

    public function test_manual_title_before_first_answer_and_disabled_title_are_respected(): void
    {
        config(['ai-chat.auto_title' => true]);
        $conversation = $this->conversation();
        Http::fake(['*' => Http::response($this->response())]);
        $this->actingAs($conversation->user);
        $url = '/ai-chat/conversations/'.$conversation->id;
        $this->patchJson($url, ['title' => '先に本人が決めた見出し'])->assertOk();
        $this->postJson($url.'/messages', ['content' => '質問'])->assertOk()->assertJsonPath('conversation.title', '先に本人が決めた見出し');
        Http::assertSentCount(1);
    }

    public function test_enabling_auto_title_after_first_answer_does_not_rename_later(): void
    {
        $conversation = $this->conversation();
        Http::fake(['*' => Http::response($this->response())]);
        $this->actingAs($conversation->user);
        $url = '/ai-chat/conversations/'.$conversation->id.'/messages';
        $this->postJson($url, ['content' => '初回'])->assertOk();
        config(['ai-chat.auto_title' => true]);
        $this->postJson($url, ['content' => '2回目'])->assertOk()->assertJsonPath('conversation.title', '新しい相談');
        Http::assertSentCount(2);
    }

    public function test_withdrawn_enrollment_or_archived_certification_is_not_sent_to_api(): void
    {
        foreach (['enrollment', 'certification', 'deleted'] as $changed) {
            $student = User::factory()->student()->create();
            [$enrollment, $section] = $this->sectionFor($student);
            $conversation = $this->conversation($student);
            $conversation->update(['enrollment_id' => $enrollment->id, 'section_id' => $section->id]);
            if ($changed === 'enrollment') {
                $enrollment->update(['status' => EnrollmentStatus::Failed]);
            } elseif ($changed === 'deleted') {
                $enrollment->delete();
            } else {
                $enrollment->certification->update(['status' => CertificationStatus::Archived]);
            }
            $this->actingAs($student)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '閲覧権変更後'])->assertNotFound();
        }
        Http::assertNothingSent();
    }

    public function test_initial_fullscreen_question_is_sent_and_failure_redirects_to_saved_history(): void
    {
        $student = User::factory()->student()->create();
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($student)->post('/ai-chat/conversations', ['source' => 'full-screen', 'message' => '最初の質問'])->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => '最初の質問']);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'assistant', 'status' => 'error']);
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
    }

    public function test_initial_question_with_missing_key_returns_input_without_empty_conversation_and_can_retry(): void
    {
        $student = User::factory()->student()->create();
        config(['ai-chat.gemini.api_key' => '']);
        $data = ['source' => 'full-screen', 'message' => '消さずに残す最初の質問'];
        $this->actingAs($student)->from('/ai-chat')->post('/ai-chat/conversations', $data)
            ->assertRedirect('/ai-chat')->assertSessionHasErrors('message')
            ->assertSessionHasInput('message', $data['message'])->assertSessionHas('error');
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        $this->get('/ai-chat')->assertOk()->assertSee($data['message']);
        Http::assertNothingSent();

        config(['ai-chat.gemini.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($this->response())]);
        $this->post('/ai-chat/conversations', $data)->assertRedirect();
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => $data['message']]);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
    }

    public function test_initial_question_at_daily_limit_returns_input_without_empty_conversation(): void
    {
        $student = User::factory()->student()->create();
        DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 20]);
        $this->actingAs($student)->from('/ai-chat')->post('/ai-chat/conversations', ['source' => 'full-screen', 'message' => '上限後の質問'])
            ->assertRedirect('/ai-chat')->assertSessionHasErrors('message')->assertSessionHasInput('message', '上限後の質問');
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 20]);
        Http::assertNothingSent();
    }

    public function test_initial_json_question_preserves_rejection_status_without_empty_conversation(): void
    {
        $student = User::factory()->student()->create();
        $this->actingAs($student);
        config(['ai-chat.gemini.api_key' => '']);
        $this->postJson('/ai-chat/conversations', ['message' => 'キー未設定'])->assertStatus(503);
        config(['ai-chat.gemini.api_key' => 'test-key']);
        DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 20]);
        $this->postJson('/ai-chat/conversations', ['message' => '上限後'])->assertTooManyRequests();
        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 20]);
        Http::assertNothingSent();
    }

    public function test_rejected_initial_question_preserves_reused_section_conversation_and_history(): void
    {
        $student = User::factory()->student()->create();
        [$enrollment, $section] = $this->sectionFor($student);
        $conversation = $this->conversation($student);
        $conversation->update(['section_id' => $section->id, 'enrollment_id' => $enrollment->id, 'title' => '残す教材相談']);
        $message = $conversation->messages()->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '残す過去の質問']);
        DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 20]);
        $this->actingAs($student)->from('/ai-chat/conversations/'.$conversation->id)
            ->post('/ai-chat/conversations', ['message' => '上限後の教材質問', 'section_id' => $section->id])
            ->assertRedirect('/ai-chat/conversations/'.$conversation->id)->assertSessionHasInput('message', '上限後の教材質問');
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseCount('ai_chat_messages', 1);
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $conversation->id, 'title' => '残す教材相談']);
        $this->assertDatabaseHas('ai_chat_messages', ['id' => $message->id, 'content' => '残す過去の質問']);
        Http::assertNothingSent();
    }

    public function test_initial_question_commits_before_answer_and_title_api_calls(): void
    {
        config(['ai-chat.auto_title' => true]);
        $student = User::factory()->student()->create();
        $transactionLevel = DB::transactionLevel();
        $calls = 0;
        Http::fake(function () use ($transactionLevel, &$calls) {
            $calls++;
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertDatabaseCount('ai_chat_conversations', 1);
            $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => '最初の質問']);

            return Http::response($this->response($calls === 1 ? '最初の回答' : '生成されたタイトル'));
        });
        $this->actingAs($student)->postJson('/ai-chat/conversations', ['message' => '最初の質問'])
            ->assertCreated()->assertJsonPath('conversation.title', '生成されたタイトル');
        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
    }

    public function test_widget_initial_question_is_saved_once_and_returns_both_messages(): void
    {
        $student = User::factory()->student()->create();
        Http::fake(['*' => Http::response($this->response('小窓の最初の回答'))]);
        $response = $this->actingAs($student)->postJson('/ai-chat/conversations', ['source' => 'widget', 'message' => '小窓の最初の質問'])
            ->assertCreated()->assertJsonPath('user_message.content', '小窓の最初の質問')
            ->assertJsonPath('assistant_message.content', '小窓の最初の回答')->assertJsonPath('assistant_message.status', 'completed');
        $this->assertNotNull($response->json('conversation.id'));
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseCount('ai_chat_messages', 2);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
        Http::assertSentCount(1);
    }

    public function test_widget_initial_rejection_does_not_leave_empty_conversation(): void
    {
        foreach ([503, 429] as $status) {
            $student = User::factory()->student()->create();
            config(['ai-chat.gemini.api_key' => $status === 503 ? '' : 'test-key']);
            if ($status === 429) {
                DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 20]);
            }
            $this->actingAs($student)->postJson('/ai-chat/conversations', ['source' => 'widget', 'message' => '拒否された小窓の質問'])->assertStatus($status);
            $this->assertDatabaseCount('ai_chat_conversations', 0);
            $this->assertDatabaseCount('ai_chat_messages', 0);
            if ($status === 429) {
                $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 20]);
            } else {
                $this->assertDatabaseCount('ai_chat_daily_usages', 0);
            }
        }
        Http::assertNothingSent();
    }

    public function test_widget_initial_api_failure_returns_saved_conversation_and_can_retry_without_new_conversation(): void
    {
        $student = User::factory()->student()->create();
        Http::fakeSequence()->push([], 503)->push($this->response('小窓の再質問への回答'));
        $response = $this->actingAs($student)->postJson('/ai-chat/conversations', ['source' => 'widget', 'message' => '失敗しても残す小窓の質問'])
            ->assertStatus(502)->assertJsonPath('assistant_message.status', 'error')->assertJsonPath('upstream_status', 503)
            ->assertJsonPath('user_message.content', '失敗しても残す小窓の質問');
        $id = $response->json('conversation.id');
        $this->assertNotNull($id);
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseHas('ai_chat_messages', ['ai_chat_conversation_id' => $id, 'role' => 'assistant', 'status' => 'error']);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 1]);
        $this->postJson('/ai-chat/conversations/'.$id.'/messages', ['content' => '失敗しても残す小窓の質問'])
            ->assertOk()->assertJsonPath('conversation.id', $id)->assertJsonPath('assistant_message.content', '小窓の再質問への回答');
        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertDatabaseCount('ai_chat_messages', 4);
        $this->assertDatabaseHas('ai_chat_daily_usages', ['user_id' => $student->id, 'attempts' => 2]);
        Http::assertSentCount(2);
    }

    public function test_pending_response_does_not_accept_duplicate_send_or_consume_quota(): void
    {
        $conversation = $this->conversation();
        $conversation->messages()->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Pending, 'content' => '']);
        $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '重複'])->assertStatus(409);
        $this->assertDatabaseCount('ai_chat_daily_usages', 0);
        Http::assertNothingSent();
    }

    public function test_history_is_limited_by_message_count_and_starts_with_user(): void
    {
        config(['ai-chat.history_messages' => 4]);
        $conversation = $this->conversation();
        for ($i = 0; $i < 4; $i++) {
            $conversation->messages()->create(['role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '過去質問'.$i]);
            $conversation->messages()->create(['role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Completed, 'content' => '過去回答'.$i]);
        }
        Http::fake(['*' => Http::response($this->response())]);
        $this->actingAs($conversation->user)->postJson('/ai-chat/conversations/'.$conversation->id.'/messages', ['content' => '現在の質問'])->assertOk();
        Http::assertSent(fn ($request) => count($request['contents']) <= 4 && $request['contents'][0]['role'] === 'user' && collect($request['contents'])->last()['parts'][0]['text'] === '現在の質問');
    }

    public function test_verification_seeder_has_general_section_and_error_history_without_api_calls(): void
    {
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $this->sectionFor($student);
        $this->seed(AiChatVerificationSeeder::class);
        $this->seed(AiChatVerificationSeeder::class);
        $this->assertSame(3, $student->aiChatConversations()->count());
        $this->assertDatabaseHas('ai_chat_messages', ['status' => 'error']);
        Http::assertNothingSent();
    }
}
