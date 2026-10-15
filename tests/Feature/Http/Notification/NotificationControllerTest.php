<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知一覧・個別既読・一括既読の HTTP 統合テスト。
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_view_only_own_notifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $this->createNotification($student, '本人の通知');
        $this->createNotification($otherStudent, '他人の通知');

        $response = $this->actingAs($student)->get(route('notifications.index'));

        $response->assertStatus(200);
        $response->assertViewIs('notifications.index');
        $response->assertSee('本人の通知');
        $response->assertDontSee('他人の通知');
    }

    public function test_coach_can_view_notifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $this->createNotification($coach, 'コーチ宛の通知');

        $response = $this->actingAs($coach)->get(route('notifications.index'));

        $response->assertStatus(200);
        $response->assertSee('コーチ宛の通知');
    }

    public function test_unread_tab_shows_only_unread_notifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->createNotification($student, '未読の通知');
        $this->createNotification($student, '既読の通知', true);

        $response = $this->actingAs($student)->get(route('notifications.index', ['tab' => 'unread']));

        $response->assertStatus(200);
        $response->assertViewHas('tab', 'unread');
        $response->assertViewHas('unreadCount', 1);
        $response->assertSee('未読の通知');
        $response->assertDontSee('既読の通知');
    }

    public function test_mark_as_read_redirects_to_related_page(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $targetUrl = route('qa-board.index');
        $notificationId = $this->createNotification($student, '回答通知', false, $targetUrl);

        $response = $this->actingAs($student)->post(route('notifications.markAsRead', $notificationId));

        $response->assertStatus(302);
        $response->assertRedirect($targetUrl);
        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_student_cannot_mark_another_users_notification_as_read(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $notificationId = $this->createNotification($otherStudent, '他人宛の通知');

        $response = $this->actingAs($student)->post(route('notifications.markAsRead', $notificationId));

        $response->assertStatus(404);
        $this->assertNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_mark_all_as_read_updates_only_own_unread_notifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $unreadId = $this->createNotification($student, '本人の未読');
        $readId = $this->createNotification($student, '本人の既読', true);
        $otherId = $this->createNotification($otherStudent, '他人の未読');
        $originalReadAt = DB::table('notifications')->where('id', $readId)->value('read_at');

        $response = $this->actingAs($student)->post(route('notifications.markAllAsRead'));

        $response->assertStatus(302);
        $response->assertRedirect(route('notifications.index'));
        $response->assertSessionHas('success');
        $this->assertNotNull(DB::table('notifications')->where('id', $unreadId)->value('read_at'));
        $this->assertSame($originalReadAt, DB::table('notifications')->where('id', $readId)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    public function test_admin_cannot_access_notification_routes(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $notificationId = $this->createNotification($admin, '管理者宛の通知');

        $this->actingAs($admin)->get(route('notifications.index'))->assertStatus(403);
        $this->actingAs($admin)->post(route('notifications.markAsRead', $notificationId))->assertStatus(403);
        $this->actingAs($admin)->post(route('notifications.markAllAsRead'))->assertStatus(403);
        $this->assertNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_graduated_student_can_view_notifications(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $this->createNotification($student, '卒業前の通知');

        $response = $this->actingAs($student)->get(route('notifications.index'));

        $response->assertStatus(200);
        $response->assertSee('卒業前の通知');
    }

    public function test_external_notification_url_redirects_to_notification_index(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $notificationId = $this->createNotification($student, '外部URL付き通知', false, 'https://example.com/');

        $response = $this->actingAs($student)->post(route('notifications.markAsRead', $notificationId));

        $response->assertStatus(302);
        $response->assertRedirect(route('notifications.index'));
        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_notification_index_paginates_twenty_records(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        for ($i = 0; $i < 21; $i++) {
            $this->createNotification($student, '通知'.($i + 1));
        }

        $firstPage = $this->actingAs($student)->get(route('notifications.index'));
        $secondPage = $this->actingAs($student)->get(route('notifications.index', ['page' => 2]));

        $firstPage->assertStatus(200);
        $firstPage->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 20 && $notifications->total() === 21);
        $secondPage->assertStatus(200);
        $secondPage->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 1 && $notifications->total() === 21);
    }

    private function createNotification(User $recipient, string $title, bool $read = false, ?string $url = null): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => QaReplyReceivedNotification::class,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'data' => json_encode([
                'notification_type' => 'qa_reply_received',
                'title' => $title,
                'message' => 'テスト用の通知です。',
                'url' => $url,
            ], JSON_THROW_ON_ERROR),
            'read_at' => $read ? now()->subMinute() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
