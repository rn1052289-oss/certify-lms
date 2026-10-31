<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;

/**
 * AI 相談の文脈(対象の受講登録・閲覧中の教材)の解決と、Gemini へ送る system prompt の組み立てを提供する Service。
 *
 * 対象にできるのは、本人の受講中(learning) / 修了済(passed) かつ公開中資格の受講登録と、公開中の Section のみ。
 * 保存済みの section_id / enrollment_id も送信のたびに再検証し、公開を取り下げた教材を外部へ送らない。
 */
class AiChatContextService
{
    /**
     * 会話に紐づける受講登録と教材を解決する。
     *
     * section_id / enrollment_id の指定があれば一致するものを必須とし(見つからなければ 404)、
     * どちらも無ければ既定の受講登録、それも無く対象が 1 件だけならその受講登録を返す。
     *
     * @return array{enrollment: ?Enrollment, section: ?Section}
     */
    public function resolve(User $user, ?string $sectionId, ?string $enrollmentId): array
    {
        $section = $sectionId === null ? null : Section::query()
            ->published()->with('chapter.part.certification')->findOrFail($sectionId);
        $query = $user->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->whereHas('certification', fn ($query) => $query->where('status', CertificationStatus::Published->value))
            ->with('certification');

        if ($section !== null) {
            $query->where('certification_id', $section->chapter->part->certification_id);
        }
        if ($enrollmentId !== null) {
            $query->whereKey($enrollmentId);
        }
        if ($section !== null || $enrollmentId !== null) {
            $enrollment = $query->firstOrFail();
        } else {
            $enrollment = $user->default_enrollment_id === null ? null : (clone $query)->find($user->default_enrollment_id);
            if ($enrollment === null && (clone $query)->count() === 1) {
                $enrollment = $query->first();
            }
        }

        return compact('enrollment', 'section');
    }

    /**
     * config の既定文に、対象資格名と閲覧中の教材(タイトル・本文)を加えた system prompt を返す。
     */
    public function prompt(User $user, AiChatConversation $conversation): string
    {
        // 保存済み ID も毎回再検証し、公開取り下げ後の教材を外部へ送らない。
        $context = $this->resolve($user, $conversation->section_id, $conversation->enrollment_id);
        $prompt = (string) config('ai-chat.system_prompt');
        if ($context['enrollment'] !== null) {
            $prompt .= "\n対象資格: ".$context['enrollment']->certification->name;
        }
        if ($context['section'] !== null) {
            $prompt .= "\n閲覧中の教材: ".$context['section']->title."\n教材本文:\n".$context['section']->body;
        }

        return $prompt;
    }
}
