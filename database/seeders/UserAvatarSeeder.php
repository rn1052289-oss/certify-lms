<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 固定受講生にアバターを設定し、設定済み・未設定の表示確認用データを用意する。
 * 共通の固定画像を使い、DB の再初期化時も孤立した画像ファイルを作らない。
 */
class UserAvatarSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->where('email', 'student@certify-lms.test')->whereNull('avatar_url')->update([
            'avatar_url' => '/images/seed-avatar.png',
        ]);
    }
}
