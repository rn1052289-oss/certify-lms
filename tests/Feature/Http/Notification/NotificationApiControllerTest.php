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
 * 通知 JSON API の本人スコープ・表示件数・既読更新・Cookie 向け CSRF の HTTP 統合テスト。
 */
class NotificationApiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_notification_api(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, '本人の通知');

        $this->getJson(route('api.notifications.index'))->assertStatus(401);
        $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(401);
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(401);
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_student_can_view_only_own_notifications_and_unread_count(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, '本人の通知');
        $this->createNotification(User::factory()->student()->inProgress()->create(), '他の受講生');
        $this->createNotification(User::factory()->coach()->inProgress()->create(), '他のコーチ');
        $this->createNotification(User::factory()->admin()->inProgress()->create(), '管理者');

        $response = $this->actingAs($student)->getJson(route('api.notifications.index'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'notifications');
        $response->assertJsonPath('notifications.0.id', $id);
        $response->assertJsonPath('notifications.0.title', '本人の通知');
        $response->assertJsonPath('notifications.0.message', 'テスト用の通知です。');
        $response->assertJsonPath('notifications.0.unread', true);
        $response->assertJsonPath('unread_count', 1);
        $response->assertJsonStructure(['notifications' => [['id', 'title', 'message', 'created_relative', 'unread']], 'unread_count']);
        $response->assertJsonMissing(['title' => '他の受講生']);
        $response->assertJsonMissing(['title' => '他のコーチ']);
        $response->assertJsonMissing(['title' => '管理者']);
    }

    public function test_coach_can_view_and_mark_only_own_notifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $id = $this->createNotification($coach, 'コーチ本人');
        $otherId = $this->createNotification(User::factory()->student()->inProgress()->create(), '受講生');

        $this->actingAs($coach)->getJson(route('api.notifications.index'))
            ->assertStatus(200)->assertJsonCount(1, 'notifications')->assertJsonPath('notifications.0.id', $id);
        $this->postJson(route('api.notifications.markAsRead', $otherId))->assertStatus(404);
        $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    public function test_graduated_student_can_view_and_mark_notifications(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $id = $this->createNotification($student, '卒業前の通知');

        $this->actingAs($student)->getJson(route('api.notifications.index'))->assertStatus(200)->assertJsonPath('notifications.0.id', $id);
        $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->createNotification($student, '卒業後の通知');
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(200)->assertJsonPath('unread_count', 0);
    }

    public function test_admin_cannot_access_notification_api(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $id = $this->createNotification($admin, '管理者の通知');

        $this->actingAs($admin)->getJson(route('api.notifications.index'))->assertStatus(403);
        $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(403);
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(403);
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_student_cannot_mark_other_users_notifications_as_read(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $recipients = [
            User::factory()->student()->inProgress()->create(),
            User::factory()->coach()->inProgress()->create(),
            User::factory()->admin()->inProgress()->create(),
        ];

        $this->actingAs($student);

        foreach ($recipients as $recipient) {
            $id = $this->createNotification($recipient, '他人の通知');

            $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(404);
            $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }

        $this->postJson(route('api.notifications.markAsRead', (string) Str::uuid()))->assertStatus(404);
    }

    public function test_notification_limit_boundaries_and_empty_data(): void
    {
        foreach ([0, 1, 9, 10, 11] as $count) {
            $student = User::factory()->student()->inProgress()->create();

            for ($i = 0; $i < $count; $i++) {
                $this->createNotification($student, '通知'.$i);
            }

            foreach (['all', 'unread'] as $tab) {
                $response = $this->actingAs($student)->getJson(route('api.notifications.index', ['tab' => $tab]));

                $response->assertStatus(200);
                $response->assertJsonCount(min($count, 10), 'notifications');
                $response->assertJsonPath('unread_count', $count);
            }
        }
    }

    public function test_notifications_are_ordered_by_created_at_descending_then_id_descending(): void
    {
        $this->freezeTime();
        $student = User::factory()->student()->inProgress()->create();
        $olderId = $this->createNotification($student, '古い通知');
        $lowerId = '00000000-0000-4000-8000-000000000001';
        $higherId = '00000000-0000-4000-8000-000000000002';
        $this->createNotification($student, '同時刻の小さいID', false, null, $lowerId);
        $this->createNotification($student, '同時刻の大きいID', false, null, $higherId);
        DB::table('notifications')->where('id', $olderId)->update(['created_at' => now()->subDay()]);

        $response = $this->actingAs($student)->getJson(route('api.notifications.index'));

        $response->assertStatus(200);
        $this->assertSame([$higherId, $lowerId, $olderId], array_column($response->json('notifications'), 'id'));
    }

    public function test_unread_tab_takes_latest_ten_after_filtering_unread_notifications(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $unreadIds = [];

        for ($i = 0; $i < 11; $i++) {
            $id = $this->createNotification($student, '未読通知'.$i);
            DB::table('notifications')->where('id', $id)->update(['created_at' => now()->subMinutes(30 - $i)]);
            $unreadIds[] = $id;
        }

        for ($i = 0; $i < 10; $i++) {
            $this->createNotification($student, '新しい既読通知'.$i, true);
        }

        $response = $this->actingAs($student)->getJson(route('api.notifications.index', ['tab' => 'unread']));

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'notifications');
        $response->assertJsonPath('unread_count', 11);
        $this->assertSame(array_slice(array_reverse($unreadIds), 0, 10), array_column($response->json('notifications'), 'id'));
        $this->assertSame([true], array_values(array_unique(array_column($response->json('notifications'), 'unread'))));
    }

    public function test_all_tab_returns_latest_ten_ids_including_read_notifications(): void
    {
        $this->freezeTime();
        $student = User::factory()->student()->inProgress()->create();
        $ids = [];

        for ($i = 0; $i < 11; $i++) {
            $id = $this->createNotification($student, '通知'.$i, $i % 2 === 0);
            DB::table('notifications')->where('id', $id)->update(['created_at' => now()->subMinutes(11 - $i)]);
            $ids[] = $id;
        }

        $response = $this->actingAs($student)->getJson(route('api.notifications.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('unread_count', 5);
        $this->assertSame(array_slice(array_reverse($ids), 0, 10), array_column($response->json('notifications'), 'id'));
    }

    public function test_all_tab_includes_read_notifications_and_unknown_tab_uses_all(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, '既読通知', true);

        foreach (['all', 'invalid'] as $tab) {
            $this->actingAs($student)->getJson(route('api.notifications.index', ['tab' => $tab]))
                ->assertStatus(200)->assertJsonCount(1, 'notifications')->assertJsonPath('notifications.0.id', $id)
                ->assertJsonPath('notifications.0.unread', false)->assertJsonPath('unread_count', 0);
        }

        $this->getJson(route('api.notifications.index', ['tab' => 'unread']))
            ->assertStatus(200)->assertExactJson(['notifications' => [], 'unread_count' => 0]);
    }

    public function test_unread_count_returns_real_total_above_badge_limit(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        for ($i = 0; $i < 100; $i++) {
            $this->createNotification($student, '未読通知'.$i);
        }

        $this->createNotification($student, '既読通知', true);
        $this->createNotification(User::factory()->coach()->inProgress()->create(), '別ユーザー');

        $this->actingAs($student)->getJson(route('api.notifications.index'))
            ->assertStatus(200)->assertJsonCount(10, 'notifications')->assertJsonPath('unread_count', 100);
        $this->get(route('notifications.index'))->assertStatus(200)->assertSee('99+')->assertSee('通知 (100 件未読)');
    }

    public function test_mark_as_read_returns_internal_url_and_preserves_read_at_on_retry(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $url = route('qa-board.index');
        $id = $this->createNotification($student, '回答通知', false, $url);
        $otherId = $this->createNotification($student, '別の未読');

        $this->actingAs($student)->postJson(route('api.notifications.markAsRead', $id))
            ->assertStatus(200)->assertExactJson(['url' => $url, 'unread_count' => 1]);
        $readAt = DB::table('notifications')->where('id', $id)->value('read_at');
        $this->assertNotNull($readAt);
        $this->travel(1)->minutes();
        $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(200)->assertJsonPath('unread_count', 1);
        $this->assertSame($readAt, DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    public function test_unsafe_or_missing_notification_url_falls_back_to_web_notification_index(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->actingAs($student);

        foreach ([null, '', 'https://example.com/', '//example.com/', 'javascript:alert(1)', url('/').'.example.com/path', '/notifications'] as $url) {
            $id = $this->createNotification($student, '遷移先のない通知', false, $url);

            $this->postJson(route('api.notifications.markAsRead', $id))->assertStatus(200)
                ->assertJsonPath('url', route('notifications.index'));
            $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }
    }

    public function test_mark_all_as_read_updates_all_own_unread_records_and_preserves_other_users(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $ownIds = [];

        for ($i = 0; $i < 11; $i++) {
            $ownIds[] = $this->createNotification($student, '本人の未読'.$i);
        }

        $readId = $this->createNotification($student, '本人の既読', true);
        $originalReadAt = DB::table('notifications')->where('id', $readId)->value('read_at');
        $otherIds = [
            $this->createNotification(User::factory()->student()->inProgress()->create(), '別の受講生'),
            $this->createNotification(User::factory()->coach()->inProgress()->create(), '別のコーチ'),
            $this->createNotification(User::factory()->admin()->inProgress()->create(), '管理者'),
        ];

        $this->actingAs($student)->postJson(route('api.notifications.markAllAsRead'))
            ->assertStatus(200)->assertExactJson(['unread_count' => 0]);

        foreach ($ownIds as $id) {
            $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }

        $updatedReadAt = DB::table('notifications')->where('id', $ownIds[0])->value('read_at');
        $this->travel(1)->minutes();
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->assertSame($originalReadAt, DB::table('notifications')->where('id', $readId)->value('read_at'));
        $this->assertSame($updatedReadAt, DB::table('notifications')->where('id', $ownIds[0])->value('read_at'));

        foreach ($otherIds as $id) {
            $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }
    }

    public function test_mark_all_as_read_succeeds_when_no_notifications_exist(): void
    {
        $student = User::factory()->student()->inProgress()->create();

        $this->actingAs($student)->postJson(route('api.notifications.markAllAsRead'))
            ->assertStatus(200)->assertExactJson(['unread_count' => 0]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_api_and_web_share_read_state_in_both_directions(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $apiId = $this->createNotification($student, 'APIで既読');
        $webId = $this->createNotification($student, 'Webで既読');

        $this->actingAs($student)->postJson(route('api.notifications.markAsRead', $apiId))->assertStatus(200);
        $this->get(route('notifications.index', ['tab' => 'unread']))
            ->assertStatus(200)->assertSee('Webで既読')->assertDontSee('APIで既読')->assertViewHas('unreadCount', 1);
        $this->post(route('notifications.markAsRead', $webId))->assertStatus(302);
        $this->getJson(route('api.notifications.index', ['tab' => 'unread']))
            ->assertStatus(200)->assertExactJson(['notifications' => [], 'unread_count' => 0]);

        $this->createNotification($student, 'Web一括既読');
        $this->post(route('notifications.markAllAsRead'))->assertStatus(302);
        $this->getJson(route('api.notifications.index'))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->createNotification($student, 'API一括既読');
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(200);
        $this->get(route('notifications.index', ['tab' => 'unread']))->assertStatus(200)->assertViewHas('unreadCount', 0);
    }

    public function test_notification_payload_uses_existing_message_fallbacks(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, '旧形式通知');
        DB::table('notifications')->where('id', $id)->update([
            'data' => json_encode(['body_preview' => '<script>旧形式の本文</script>'], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($student)->getJson(route('api.notifications.index'))->assertStatus(200)
            ->assertJsonPath('notifications.0.title', '通知')->assertJsonPath('notifications.0.message', '<script>旧形式の本文</script>');
    }

    public function test_stateful_mutations_reject_missing_and_invalid_csrf_tokens(): void
    {
        $this->app['env'] = 'local';
        $origin = url('/');
        config(['sanctum.stateful' => [parse_url($origin, PHP_URL_HOST).(parse_url($origin, PHP_URL_PORT) ? ':'.parse_url($origin, PHP_URL_PORT) : '')]]);
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, 'CSRFで保護する通知');
        $this->actingAs($student)->withHeaders(['Origin' => $origin])->withSession(['_token' => 'valid-csrf-token']);

        foreach ([route('api.notifications.markAsRead', $id), route('api.notifications.markAllAsRead')] as $url) {
            $this->postJson($url)->assertStatus(419);
            $this->withHeader('X-CSRF-TOKEN', 'invalid-csrf-token')->postJson($url)->assertStatus(419);
            $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
            $this->flushHeaders();
            $this->withHeaders(['Origin' => $origin]);
        }
    }

    public function test_stateful_mutations_accept_valid_csrf_token(): void
    {
        $this->app['env'] = 'local';
        $origin = url('/');
        config(['sanctum.stateful' => [parse_url($origin, PHP_URL_HOST).(parse_url($origin, PHP_URL_PORT) ? ':'.parse_url($origin, PHP_URL_PORT) : '')]]);
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, '単一既読');
        $otherId = $this->createNotification($student, '一括既読');

        $this->actingAs($student)->withSession(['_token' => 'valid-csrf-token'])
            ->withHeaders(['Origin' => $origin, 'X-CSRF-TOKEN' => 'valid-csrf-token'])
            ->postJson(route('api.notifications.markAsRead', $id))->assertStatus(200)->assertJsonPath('unread_count', 1);
        $this->postJson(route('api.notifications.markAllAsRead'))->assertStatus(200)->assertJsonPath('unread_count', 0);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertNotNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
    }

    private function createNotification(User $recipient, string $title, bool $read = false, ?string $url = null, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();

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
