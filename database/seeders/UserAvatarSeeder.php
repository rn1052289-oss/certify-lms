<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\UseCases\UserAvatar\StoreAction;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * 固定受講生にアバターを設定し、設定済み・未設定の表示確認用データを用意する。
 */
class UserAvatarSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->whereNull('avatar_url')->first();

        if ($student === null) {
            return;
        }

        app(StoreAction::class)($student, new UploadedFile(
            database_path('seeders/assets/avatar.png'),
            'avatar.png',
            'image/png',
            null,
            true,
        ));
    }
}
