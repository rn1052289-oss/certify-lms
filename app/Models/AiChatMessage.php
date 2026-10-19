<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiChatMessage extends Model
{
    use HasUlids;

    protected $fillable = ['ai_chat_conversation_id', 'role', 'status', 'content', 'model', 'input_tokens', 'output_tokens', 'response_time_ms', 'error_detail'];

    protected $hidden = ['model', 'input_tokens', 'output_tokens', 'response_time_ms', 'error_detail'];

    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'ai_chat_conversation_id');
    }
}
