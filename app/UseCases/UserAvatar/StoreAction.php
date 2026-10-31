<?php

declare(strict_types=1);

namespace App\UseCases\UserAvatar;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 本人のプロフィール画像を保存するユースケース。
 *
 * 処理フロー:
 * 1. avatars/{user_id} に ULID のファイル名で保存する
 * 2. avatar_url を更新する。更新に失敗したら保存した画像を削除して例外を投げ直す
 * 3. 本人のディレクトリにある旧画像だけを削除する
 */
final class StoreAction
{
    /**
     * @throws \RuntimeException 画像を保存できなかった
     */
    public function __invoke(User $user, UploadedFile $file): void
    {
        $directory = "avatars/{$user->id}";
        $filename = Str::ulid().'.'.$file->extension();

        $path = Storage::disk('public')->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new \RuntimeException('アバター画像の保存に失敗しました。');
        }

        try {
            $oldUrl = DB::transaction(function () use ($user, $path): ?string {
                $target = User::query()->lockForUpdate()->findOrFail($user->id);
                $previousUrl = $target->avatar_url;

                $target->update([
                    'avatar_url' => "/storage/{$path}",
                ]);

                return $previousUrl;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }

        if (is_string($oldUrl) && str_starts_with($oldUrl, "/storage/avatars/{$user->id}/")) {
            Storage::disk('public')->delete(substr($oldUrl, strlen('/storage/')));
        }
    }
}
