<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 3種類の配信履歴と受信通知を用意する。メールは送信しない。
 */
final class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@certify-lms.test')->first();
        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        $certification = $student?->enrollments()->where('status', EnrollmentStatus::Learning)->first()?->certification;

        if ($admin === null || $student === null || $certification === null) {
            $this->command?->warn('AnnouncementSeeder: 固定アカウントまたは学習中の資格が存在しません。');

            return;
        }

        foreach (AnnouncementTargetType::cases() as $index => $type) {
            $title = '【'.$type->label().'】運営からのお知らせ';

            if (Announcement::query()->where('title', $title)->exists()) {
                continue;
            }

            $announcement = Announcement::query()->create([
                'title' => $title,
                'body' => "学習を進めていただきありがとうございます。\n教材の更新と面談予約をご確認ください。\nご不明な点は担当コーチまでお知らせください。",
                'target_type' => $type,
                'target_certification_id' => $type === AnnouncementTargetType::Certification ? $certification->id : null,
                'target_user_id' => $type === AnnouncementTargetType::User ? $student->id : null,
                'created_by_user_id' => $admin->id,
                'dispatched_at' => now()->subMinutes(3 - $index),
            ]);

            $recipients = User::query()->where('role', UserRole::Student)->where('status', UserStatus::InProgress);

            if ($type === AnnouncementTargetType::Certification) {
                $recipients->whereHas('enrollments', fn ($query) => $query
                    ->where('certification_id', $certification->id)->where('status', EnrollmentStatus::Learning));
            } elseif ($type === AnnouncementTargetType::User) {
                $recipients->whereKey($student->id);
            }

            $recipients->chunkById(100, function ($users) use ($announcement): void {
                foreach ($users as $user) {
                    $notification = new AdminAnnouncementNotification($announcement);
                    $notification->id = (string) Str::uuid();
                    $user->notifications()->create([
                        'id' => $notification->id,
                        'type' => $notification::class,
                        'data' => $notification->toDatabase($user),
                        'created_at' => $announcement->dispatched_at,
                    ]);
                    $announcement->increment('dispatched_count');
                }
            });
        }
    }
}
