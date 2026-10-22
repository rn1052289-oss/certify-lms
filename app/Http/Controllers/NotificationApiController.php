<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知ポップオーバー向け JSON API。取得・既読化は常に本人の通知に限定する。
 * 既存 Web 一覧と同じ通知を扱い、各タブの最新 10 件と未読の実総数を返す。
 */
class NotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $request->query('tab') === 'unread'
            ? $user->unreadNotifications()
            : $user->notifications();

        return response()->json([
            'notifications' => $notifications->orderByDesc('id')->limit(10)->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? '通知',
                    'message' => $notification->data['message'] ?? ($notification->data['body_preview'] ?? ''),
                    'created_relative' => $notification->created_at?->diffForHumans() ?? '',
                    'unread' => $notification->read_at === null,
                ]),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        $url = $record->data['url'] ?? null;

        return response()->json([
            'url' => is_string($url) && str_starts_with($url, url('/').'/')
                ? $url
                : route('notifications.index'),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
