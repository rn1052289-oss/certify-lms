<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 受講生・コーチ本人の通知。既存 Web 一覧と並行して提供する。
Route::middleware(['auth:sanctum', 'role:student,coach'])
    ->prefix('v1/notifications')
    ->name('api.notifications.')
    ->group(function () {
        Route::get('/', [NotificationApiController::class, 'index'])->name('index');
        Route::post('{notification}/read', [NotificationApiController::class, 'markAsRead'])->name('markAsRead');
        Route::post('read-all', [NotificationApiController::class, 'markAllAsRead'])->name('markAllAsRead');
    });
