<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\GeminiException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChatContextService;
use App\Services\GeminiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMessageAction
{
    public function __construct(
        private readonly AiChatContextService $context,
        private readonly GeminiClient $gemini,
    ) {}

    /** @return array{user_message: AiChatMessage, assistant_message: AiChatMessage, conversation: AiChatConversation, upstream_status: ?int} */
    public function __invoke(User $user, AiChatConversation $conversation, string $content): array
    {
        abort_if(trim((string) config('ai-chat.gemini.api_key')) === '', 503, 'AI 相談は現在利用できません。API キーが設定されていません。');
        $systemPrompt = $this->context->prompt($user, $conversation);
        [$userMessage, $assistantMessage] = DB::transaction(function () use ($user, $conversation, $content): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->can('view', $conversation), 403);
            $locked = AiChatConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->messages()->where('status', AiChatMessageStatus::Pending->value)->exists(), 409, 'AI が応答中です。完了後に送信してください。');
            $date = now()->toDateString();
            DB::table('ai_chat_daily_usages')->insertOrIgnore(['user_id' => $user->id, 'usage_date' => $date, 'attempts' => 0]);
            $usage = DB::table('ai_chat_daily_usages')->where('user_id', $user->id)->where('usage_date', $date);
            abort_if($usage->value('attempts') >= (int) config('ai-chat.daily_limit'), 429, '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。');
            $usage->increment('attempts');
            $userMessage = $locked->messages()->create([
                'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => $content,
            ]);
            $assistantMessage = $locked->messages()->create([
                'role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Pending, 'content' => '',
            ]);
            $locked->update(['last_message_at' => now()]);

            return [$userMessage, $assistantMessage];
        });

        $upstreamStatus = null;
        $started = hrtime(true);
        try {
            $history = $conversation->messages()->reorder()->where('status', AiChatMessageStatus::Completed->value)
                ->orderByDesc('id')->limit((int) config('ai-chat.history_messages'))->get()->reverse()
                ->skipWhile(fn (AiChatMessage $message) => $message->role === AiChatMessageRole::Assistant)->values();
            $contents = $history->map(fn (AiChatMessage $message) => [
                'role' => $message->role === AiChatMessageRole::Assistant ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ])->all();
            $result = $this->gemini->generate($contents, $systemPrompt);
            $generateTitle = DB::transaction(function () use ($conversation, $assistantMessage, $result): bool {
                $locked = AiChatConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $assistantMessage->update($result + ['status' => AiChatMessageStatus::Completed]);
                if (! config('ai-chat.auto_title') || $locked->title_manually_edited || $locked->auto_title_attempted_at !== null) {
                    return false;
                }
                if ($locked->messages()->where('role', AiChatMessageRole::Assistant->value)->where('status', AiChatMessageStatus::Completed->value)->count() !== 1) {
                    return false;
                }
                // 正常回答の確定と一緒に権利を確保し、同時送信や設定変更でも2回目を生成しない。
                $locked->update(['auto_title_attempted_at' => now()]);

                return true;
            });
            if ($generateTitle) {
                $this->generateTitle($conversation, $content, $result['content']);
            }
        } catch (Throwable $exception) {
            $upstreamStatus = $exception instanceof GeminiException ? $exception->upstreamStatus : null;
            $assistantMessage->update([
                'status' => AiChatMessageStatus::Error,
                'content' => 'AI が応答できませんでした。しばらく時間をおいて再試行してください。',
                'model' => config('ai-chat.gemini.model'),
                'error_detail' => $upstreamStatus === null ? '応答の取得に失敗' : 'HTTP '.$upstreamStatus,
                'response_time_ms' => (int) round((hrtime(true) - $started) / 1000000),
            ]);
            // 一時的な保存失敗でも復旧後に再質問できるよう、pending を残さない。
            Log::channel('ai-chat')->warning('AI 相談の応答を完了できませんでした。', [
                'message_id' => $assistantMessage->id, 'exception_type' => $exception::class,
            ]);
        }
        Log::channel('ai-chat')->info('AI 相談の応答処理', [
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
            'status' => $assistantMessage->status->value,
            'model' => $assistantMessage->model,
            'input_tokens' => $assistantMessage->input_tokens,
            'output_tokens' => $assistantMessage->output_tokens,
            'response_time_ms' => $assistantMessage->response_time_ms,
        ]);

        return [
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'conversation' => $conversation->refresh(),
            'upstream_status' => $upstreamStatus,
        ];
    }

    private function generateTitle(AiChatConversation $conversation, string $question, string $answer): void
    {
        try {
            $result = $this->gemini->generate([
                ['role' => 'user', 'parts' => [['text' => '質問: '.$question."\n回答: ".$answer]]],
            ], 'この相談内容に合う日本語の短いタイトルを100文字以内で1つだけ返してください。説明や引用符は不要です。');
            $title = mb_substr(trim($result['content']), 0, 100);
            if ($title !== '') {
                // 外部 API 待機中に本人が変更したタイトルも保護する。
                AiChatConversation::query()->whereKey($conversation->id)->where('title_manually_edited', false)->update(['title' => $title]);
            }
            Log::channel('ai-chat')->info('AI 相談のタイトル生成', array_diff_key($result, ['content' => true]) + ['conversation_id' => $conversation->id]);
        } catch (Throwable $exception) {
            Log::channel('ai-chat')->warning('AI 相談のタイトルを生成できませんでした。', [
                'conversation_id' => $conversation->id, 'exception_type' => $exception::class,
                'upstream_status' => $exception instanceof GeminiException ? $exception->upstreamStatus : null,
            ]);
        }
    }
}
