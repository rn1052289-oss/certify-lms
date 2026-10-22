<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 固定受講生の履歴・教材・失敗状態を実 API なしで確認する検証用データ。 */
class AiChatVerificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('AI 相談の検証用 Seeder は local / testing 専用です。');
        }
        $student = User::query()->where('email', 'student@certify-lms.test')->firstOrFail();
        $enrollment = $student->enrollments()->learning()->whereHas('certification', fn ($query) => $query->published())->firstOrFail();
        $section = Section::query()->published()->whereHas('chapter.part', fn ($query) => $query->where('certification_id', $enrollment->certification_id))->firstOrFail();
        foreach ([['全般相談の確認', null, false], ['教材相談の確認', $section->id, false], ['エラー履歴の確認', null, true]] as [$title, $sectionId, $error]) {
            $conversation = $student->aiChatConversations()->firstOrCreate(['title' => $title], [
                'enrollment_id' => $enrollment->id, 'section_id' => $sectionId, 'last_message_at' => now(),
                'title_manually_edited' => true,
            ]);
            if ($conversation->messages()->exists()) {
                continue;
            }
            $conversation->messages()->create([
                'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed,
                'content' => $sectionId === null ? '学習の進め方を教えてください。' : 'この教材の要点を説明してください。',
            ]);
            $conversation->messages()->create([
                'role' => AiChatMessageRole::Assistant,
                'status' => $error ? AiChatMessageStatus::Error : AiChatMessageStatus::Completed,
                'content' => $error ? 'AI が応答できませんでした。同じ内容を送り直して再質問できます。' : 'これは検証用の回答です。教材の要点を整理し、分からない部分はコーチにも相談しましょう。',
                'model' => 'verification-fixture', 'input_tokens' => 40, 'output_tokens' => 30, 'response_time_ms' => 1250,
                'error_detail' => $error ? 'HTTP 503' : null,
            ]);
        }
    }
}
