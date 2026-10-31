<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 相談の 1 発言(受講生の質問 / AI の回答)を表す Model。
 *
 * role で発言者、status で回答状態(pending / completed / error)を表す。
 * model / input_tokens / output_tokens / response_time_ms / error_detail は運用記録として保存し、画面と JSON には出さない($hidden)。
 *
 * 関連: AiChatConversation(会話)
 */
class AiChatMessage extends Model
{
    use HasUlids;

    protected $fillable = ['ai_chat_conversation_id', 'role', 'status', 'content', 'model', 'input_tokens', 'output_tokens', 'response_time_ms', 'error_detail'];

    protected $hidden = ['model', 'input_tokens', 'output_tokens', 'response_time_ms', 'error_detail'];

    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
    ];

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'ai_chat_conversation_id');
    }
}
