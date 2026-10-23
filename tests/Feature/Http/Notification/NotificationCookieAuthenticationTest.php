<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification;

use App\Models\User;
use App\Notifications\QaReplyReceivedNotification;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * actingAs を使わず、ログイン応答の暗号化 Cookie だけで通知 API を利用する HTTP 統合テスト。
 */
class NotificationCookieAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionDirectory = sys_get_temp_dir().'/certify-notification-cookie-'.Str::uuid();
        (new Filesystem)->ensureDirectoryExists($this->sessionDirectory);
        $this->app['env'] = 'local';
        config([
            'session.driver' => 'file',
            'session.files' => $this->sessionDirectory,
            'sanctum.stateful' => [parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '')],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            (new Filesystem)->deleteDirectory($this->sessionDirectory);
        }
    }

    public function test_login_cookies_restore_only_their_own_student_or_coach_session(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $studentId = $this->createNotification($student, '受講生本人の通知');
        $coachId = $this->createNotification($coach, 'コーチ本人の通知');

        foreach ([[$student, $studentId, $coachId], [$coach, $coachId, $studentId]] as [$user, $ownId, $otherId]) {
            $cookies = $this->loginCookies($user);

            $this->cookieRequest('GET', route('api.notifications.index'), $cookies)
                ->assertOk()->assertJsonCount(1, 'notifications')
                ->assertJsonPath('notifications.0.id', $ownId)->assertJsonPath('unread_count', 1);
            $this->cookieRequest('GET', route('api.notifications.index'), [])
                ->assertStatus(401);
            $this->cookieRequest('GET', route('api.notifications.index'), $cookies, [], false)
                ->assertStatus(401);
            $this->cookieRequest('POST', route('api.notifications.markAsRead', $otherId), $cookies, [
                'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
            ])->assertStatus(404);
            $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
        }
    }

    public function test_cookie_mutations_require_csrf_and_share_read_state_with_web(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $id = $this->createNotification($student, 'Cookieで単一既読');
        $otherId = $this->createNotification($student, 'Cookieで全件既読');
        $cookies = $this->loginCookies($student);

        foreach ([route('api.notifications.markAsRead', $id), route('api.notifications.markAllAsRead')] as $url) {
            $this->cookieRequest('POST', $url, $cookies)->assertStatus(419);
            $this->cookieRequest('POST', $url, $cookies, ['HTTP_X_CSRF_TOKEN' => 'invalid-csrf-token'])
                ->assertStatus(419);
            $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
            $this->assertNull(DB::table('notifications')->where('id', $otherId)->value('read_at'));
        }

        $this->cookieRequest('POST', route('api.notifications.markAsRead', $id), $cookies, [
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
        ])->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('url', route('notifications.index'));
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->cookieRequest('GET', route('notifications.index', ['tab' => 'unread']), $cookies)
            ->assertOk()->assertViewHas('unreadCount', 1)->assertSee('Cookieで全件既読')->assertDontSee('Cookieで単一既読');
        $this->cookieRequest('POST', route('api.notifications.markAllAsRead'), $cookies, [
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
        ])->assertOk()->assertJsonPath('unread_count', 0);
        $this->cookieRequest('GET', route('api.notifications.index', ['tab' => 'unread']), $cookies)
            ->assertOk()->assertExactJson(['notifications' => [], 'unread_count' => 0]);
    }

    public function test_admin_login_cookie_does_not_allow_notification_api(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $id = $this->createNotification($admin, '管理者の通知');
        $cookies = $this->loginCookies($admin);

        $this->cookieRequest('GET', route('api.notifications.index'), $cookies)->assertStatus(403);
        foreach ([route('api.notifications.markAsRead', $id), route('api.notifications.markAllAsRead')] as $url) {
            $this->cookieRequest('POST', $url, $cookies, [
                'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
            ])->assertStatus(403);
        }
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    /**
     * @return array<string, string>
     */
    private function loginCookies(User $user): array
    {
        $password = Str::random(32);
        $user->update(['password' => $password]);
        $csrfResponse = $this->cookieRequest('GET', '/sanctum/csrf-cookie', []);
        $csrfResponse->assertStatus(204);
        $cookies = $this->responseCookies($csrfResponse);
        $this->assertArrayHasKey(config('session.cookie'), $cookies);
        $this->assertArrayHasKey('XSRF-TOKEN', $cookies);

        $response = $this->cookieRequest('POST', '/login', $cookies, [
            'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
        ], true, ['email' => $user->email, 'password' => $password]);
        $response->assertOk();

        return array_replace($cookies, $this->responseCookies($response));
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, string> $parameters
     */
    private function cookieRequest(string $method, string $url, array $cookies, array $headers = [], bool $stateful = true, array $parameters = []): TestResponse
    {
        // 前リクエストの認証・session store を引き継がず、送信 Cookie から復元させる。
        Auth::forgetGuards();
        Auth::setDefaultDriver('web');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $this->call($method, $url, $parameters, $cookies, [], array_merge([
            'HTTP_ACCEPT' => 'application/json',
        ], $stateful ? ['HTTP_REFERER' => url('/notifications')] : [], $headers));
    }

    /**
     * @return array<string, string>
     */
    private function responseCookies(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    private function createNotification(User $recipient, string $title): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => QaReplyReceivedNotification::class,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'data' => json_encode(['title' => $title, 'message' => 'Cookie認証の検証通知です。'], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
