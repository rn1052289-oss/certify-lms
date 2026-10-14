<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開発用質問掲示板シーダー。
 *
 * 公開済み資格ごとに未解決・解決済みの質問を作成し、
 * 回答数・投稿者・作成日時の異なる初期データを用意する。
 */
final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $certifications = Certification::query()->published()->with('coaches')->orderBy('name')->get();

        $student = User::query()
            ->where('email', 'student@certify-lms.test')
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->first();

        if ($certifications->isEmpty() || $student === null) {
            $this->command?->warn('QaBoardSeeder: 公開済み資格または固定受講生が存在しません。');

            return;
        }

        $students = User::query()
            ->where('role', UserRole::Student->value)
            ->where('status', UserStatus::InProgress->value)
            ->orderBy('email')
            ->get();

        $titles = [
            '学習計画の立て方について',
            '過去問の復習方法について',
            '苦手分野の克服方法について',
            '試験直前の勉強方法について',
            '模試の結果の振り返りについて',
        ];

        foreach ($certifications as $certificationIndex => $certification) {
            for ($i = 0; $i < 5; $i++) {
                $author = $i === 0
                    ? $student
                    : $students[($certificationIndex + $i) % $students->count()];

                $createdAt = now()->subDays($certificationIndex * 5 + $i + 1);
                $isResolved = $i % 2 === 1;

                $thread = QaThread::factory()->create([
                    'certification_id' => $certification->id,
                    'user_id' => $author->id,
                    'title' => $titles[$i],
                    'body' => $certification->name.'について学習しています。'.$titles[$i].'おすすめの進め方があれば教えてください。',
                    'status' => $isResolved ? QaThreadStatus::Resolved->value : QaThreadStatus::Unresolved->value,
                    'resolved_at' => $isResolved ? $createdAt->copy()->addHours(6) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $replyCount = match ($i) {
                    0, 3 => 0,
                    1, 4 => 2,
                    2 => 3,
                };

                for ($j = 0; $j < $replyCount; $j++) {
                    $replyAuthor = $j % 2 === 0
                        ? ($certification->coaches->first() ?? $student)
                        : $students[($certificationIndex + $i + $j) % $students->count()];

                    $repliedAt = $createdAt->copy()->addHours($j + 1);

                    QaReply::factory()->create([
                        'qa_thread_id' => $thread->id,
                        'user_id' => $replyAuthor->id,
                        'body' => 'まずは学習内容を整理し、理解が不十分な部分から復習することをおすすめします。',
                        'created_at' => $repliedAt,
                        'updated_at' => $repliedAt,
                    ]);
                }
            }
        }
    }
}
