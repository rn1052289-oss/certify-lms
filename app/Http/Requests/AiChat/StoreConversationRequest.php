<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\Models\AiChatConversation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受講生の AI 相談会話の作成リクエスト。
 *
 * - source: 受付元(widget / full-screen)
 * - section_id / enrollment_id: 教材から始める相談のときだけ受け取る
 * - message: 小窓では最初の質問を同じリクエストで受け付ける(2000 文字まで)
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AiChatConversation::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', 'in:widget,full-screen'],
            'section_id' => ['nullable', 'ulid'],
            'enrollment_id' => ['nullable', 'ulid'],
            'message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
