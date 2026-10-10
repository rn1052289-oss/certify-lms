<?php

declare(strict_types=1);

namespace App\UseCases\UserAvatar;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StoreAction
{
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
