<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;

class AiChatContextService
{
    /** @return array{enrollment: ?Enrollment, section: ?Section} */
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
