<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** ローカルの通知キュー検証用アカウントを用意する。メールは送信しない。 */
final class NotificationQueueVerificationSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'queue-reset@certify-lms.test'], [
            'name' => 'キュー検証用受講生',
            'role' => UserRole::Student,
            'status' => UserStatus::InProgress,
            'password' => Hash::make('password'),
            'profile_setup_completed' => true,
            'email_verified_at' => now(),
        ]);
    }
}
