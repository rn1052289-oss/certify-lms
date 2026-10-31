<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 受講生と AI(Gemini) の相談 1 件分を表す Model。
 *
 * 教材(Section) の小窓から始めた相談は enrollment_id / section_id を持ち、全画面の一般相談はどちらも NULL になる。
 * 教材が削除されても会話は残し、section_id だけを NULL にする(会話・メッセージ・日次利用回数は保持)。
 *
 * 関連: User(相談した受講生) / Enrollment(受講登録) / Section(相談元の教材) / AiChatMessage(発言、送信順)
 * 主要 Action: `StoreConversationAction` / `SendMessageAction`
 */
class AiChatConversation extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'enrollment_id', 'section_id', 'title', 'title_manually_edited', 'auto_title_attempted_at', 'last_message_at'];

    protected $casts = [
        'title_manually_edited' => 'boolean',
        'auto_title_attempted_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * @return HasMany<AiChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class)->orderBy('id');
    }
}
