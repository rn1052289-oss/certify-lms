<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChatRoom;
use App\Models\Meeting;
use App\Models\QaReply;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 固定アカウント向けの通知デモデータ。
 * 既存業務データを参照して DB に直接投入し、メールは送らない。
 */
final class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();
        $coach = User::query()->where('email', 'coach@certify-lms.test')->first();

        if ($student === null || $coach === null) {
            $this->command?->warn('NotificationSeeder: 固定受講生または固定コーチが存在しません。');

            return;
        }

        $this->seedChat($student, $coach);
        $this->seedMeetings($student, $coach);
        $this->seedQaReplies();
    }

    private function seedChat(User $student, User $coach): void
    {
        $room = ChatRoom::query()
            ->whereHas('enrollment', fn ($query) => $query->where('user_id', $student->id))
            ->whereHas('members', fn ($query) => $query->where('user_id', $coach->id))
            ->orderBy('created_at')
            ->first();

        if ($room === null) {
            $this->command?->warn('NotificationSeeder: 固定アカウント共通のチャットルームが存在しません。');

            return;
        }

        $coachMessages = $room->messages()->with('sender')->where('sender_user_id', $coach->id)->get();

        if ($coachMessages->isNotEmpty()) {
            // 全件・未読のみの両方で 2 ページ目を確認できるよう 32 件用意する。
            for ($i = 0; $i < 32; $i++) {
                $message = $coachMessages->get($i % $coachMessages->count());
                $this->insertNotification($student, new ChatMessageReceivedNotification($message), 10 + $i * 4, $i % 3 === 0);
            }
        }

        $studentMessages = $room->messages()->with('sender')->where('sender_user_id', $student->id)->get();

        if ($studentMessages->isNotEmpty()) {
            for ($i = 0; $i < 6; $i++) {
                $message = $studentMessages->get($i % $studentMessages->count());
                $this->insertNotification($coach, new ChatMessageReceivedNotification($message), 15 + $i * 7, $i % 2 === 0);
            }
        }
    }

    private function seedMeetings(User $student, User $coach): void
    {
        $reserved = Meeting::query()
            ->where('student_id', $student->id)
            ->where('coach_id', $coach->id)
            ->where('status', MeetingStatus::Reserved->value)
            ->first();

        if ($reserved !== null) {
            $this->insertNotification($coach, new MeetingReservedNotification($reserved), 3, false);
        }

        $canceledMeetings = Meeting::query()
            ->where('status', MeetingStatus::Canceled->value)
            ->with(['student', 'coach'])
            ->get();

        foreach ($canceledMeetings as $i => $meeting) {
            $canceledByStudent = $meeting->canceled_by_user_id === $meeting->student_id;
            $canceledByCoach = $meeting->canceled_by_user_id === $meeting->coach_id;

            if (! $canceledByStudent && ! $canceledByCoach) {
                continue;
            }

            $recipient = $canceledByStudent ? $meeting->coach : $meeting->student;
            $expectedRole = $canceledByStudent ? UserRole::Coach : UserRole::Student;

            if ($recipient === null || $recipient->role !== $expectedRole || $recipient->status !== UserStatus::InProgress) {
                continue;
            }

            $this->insertNotification($recipient, new MeetingCanceledNotification($meeting), 100 + $i * 10, $i % 2 === 0);
        }
    }

    private function seedQaReplies(): void
    {
        $replies = QaReply::query()->with(['qaThread.user', 'user'])->orderBy('created_at')->limit(20)->get();

        foreach ($replies as $i => $reply) {
            $questioner = $reply->qaThread?->user;

            if (
                $questioner === null
                || $questioner->id === $reply->user_id
                || $questioner->role !== UserRole::Student
                || $questioner->status !== UserStatus::InProgress
                || $questioner->trashed()
            ) {
                continue;
            }

            $this->insertNotification($questioner, new QaReplyReceivedNotification($reply), 150 + $i * 10, $i % 2 === 0);
        }
    }

    private function insertNotification(User $recipient, Notification $notification, int $minutesAgo, bool $read): void
    {
        $createdAt = now()->subMinutes($minutesAgo);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => $notification::class,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'data' => json_encode($notification->toDatabase($recipient), JSON_THROW_ON_ERROR),
            'read_at' => $read ? $createdAt->copy()->addMinute() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
