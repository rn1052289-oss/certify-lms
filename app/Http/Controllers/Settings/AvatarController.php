<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserAvatar\StoreRequest;
use App\UseCases\UserAvatar\DestroyAction;
use App\UseCases\UserAvatar\StoreAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ログイン中ユーザー本人のアバター画像を登録・削除する。
 */
class AvatarController extends Controller
{
    public function store(StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $action($request->user(), $request->file('avatar'));

        return redirect()->route('settings.profile.edit')->with('success', 'アイコン画像を更新しました。');
    }

    public function destroy(Request $request, DestroyAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()->route('settings.profile.edit')->with('success', 'アイコン画像を削除しました。');
    }
}
