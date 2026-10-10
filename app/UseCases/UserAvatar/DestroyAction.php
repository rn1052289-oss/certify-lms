<?php

declare(strict_types=1);

namespace App\UseCases\UserAvatar;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DestroyAction
{
    public function __invoke(User $user): void
    {
        $oldUrl = DB::transaction(function () use ($user): ?string {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $previousUrl = $target->avatar_url;

            $target->update(['avatar_url' => null]);

            return $previousUrl;
        });

        if (is_string($oldUrl) && str_starts_with($oldUrl, "/storage/avatars/{$user->id}/")) {
            Storage::disk('public')->delete(substr($oldUrl, strlen('/storage/')));
        }
    }
}
