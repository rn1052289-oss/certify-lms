<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\EnrollmentStatus;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Database\Seeders\AnnouncementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => '運営からのお知らせ', 'body' => "本文の1行目\n本文の2行目",
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ], $overrides);
    }

    public function test_admin_can_send_to_active_students_and_view_history(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $students = User::factory()->student()->inProgress()->count(2)->create();
        User::factory()->coach()->inProgress()->create();
        User::factory()->student()->graduated()->create();
        User::factory()->student()->create(['status' => UserStatus::Invited]);
        User::factory()->student()->create(['status' => UserStatus::Withdrawn]);
        User::factory()->student()->inProgress()->create()->delete();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload());
        $announcement = Announcement::query()->sole();

        $response->assertRedirect(route('admin.announcements.show', $announcement))->assertSessionHas('success');
        $this->assertSame(2, $announcement->dispatched_count);
        $this->assertSame($admin->id, $announcement->created_by_user_id);
        $this->assertDatabaseCount('notifications', 2);
        foreach ($students as $student) {
            $this->assertSame(['database', 'mail'], (new AdminAnnouncementNotification($announcement))->via($student));
            $this->assertSame($announcement->body, $student->notifications()->sole()->data['body']);
        }
        $this->get(route('admin.announcements.index'))->assertOk()->assertSee($announcement->title)->assertSee('全受講生');
        $this->get(route('admin.announcements.show', $announcement))->assertOk()->assertSee($announcement->body);
    }

    public function test_certification_targets_only_active_students_with_learning_enrollment(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $certification = Certification::factory()->create();
        $target = User::factory()->student()->inProgress()->create();
        Enrollment::factory()->create(['user_id' => $target->id, 'certification_id' => $certification->id, 'status' => EnrollmentStatus::Learning]);
        foreach ([EnrollmentStatus::Passed, EnrollmentStatus::Failed] as $status) {
            Enrollment::factory()->create(['certification_id' => $certification->id, 'status' => $status]);
        }
        $graduated = User::factory()->student()->graduated()->create();
        Enrollment::factory()->create(['user_id' => $graduated->id, 'certification_id' => $certification->id]);
        Enrollment::factory()->create();
        Enrollment::factory()->create(['certification_id' => $certification->id])->delete();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => 'certification', 'target_certification_id' => $certification->id,
        ]))->assertSessionHas('success');

        $this->assertSame(1, Announcement::query()->sole()->dispatched_count);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $target->notifications()->count());
    }

    public function test_user_target_delivers_to_selected_student_only(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        User::factory()->student()->inProgress()->create();

        $this->actingAs($admin)->post(route('admin.announcements.store'), $this->payload([
            'target_type' => 'user', 'target_user_id' => $student->id,
        ]))->assertSessionHas('success');

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $student->notifications()->count());
    }

    public function test_only_admin_can_access_management_routes(): void
    {
        $announcement = Announcement::query()->create($this->payload());
        $this->get('/admin/announcements')->assertRedirect('/login');
        foreach (['student', 'coach'] as $role) {
            $user = User::factory()->inProgress()->create(['role' => $role]);
            $this->actingAs($user)->get('/admin/announcements')->assertForbidden();
            $this->get('/admin/announcements/create')->assertForbidden();
            $this->post('/admin/announcements', $this->payload())->assertForbidden();
            $this->get('/admin/announcements/'.$announcement->id)->assertForbidden();
        }
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_validation_rejects_missing_and_overlong_fields_and_invalid_targets(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $this->actingAs($admin);
        foreach ([
            [['title' => '', 'body' => ''], ['title', 'body']],
            [['title' => str_repeat('あ', 201), 'body' => str_repeat('あ', 5001)], ['title', 'body']],
            [['target_type' => 'invalid'], ['target_type']],
            [['target_type' => 'certification'], ['target_certification_id']],
            [['target_type' => 'user'], ['target_user_id']],
            [['target_type' => 'user', 'target_user_id' => $admin->id], ['target_user_id']],
        ] as [$overrides, $errors]) {
            $this->post('/admin/announcements', $this->payload($overrides))->assertSessionHasErrors($errors);
        }
        foreach ([UserStatus::Invited, UserStatus::Graduated, UserStatus::Withdrawn] as $status) {
            $student = User::factory()->student()->create(['status' => $status]);
            $this->post('/admin/announcements', $this->payload(['target_type' => 'user', 'target_user_id' => $student->id]))->assertSessionHasErrors('target_user_id');
        }
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_maximum_lengths_and_unused_target_inputs_are_accepted(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $this->actingAs($admin)->post('/admin/announcements', $this->payload([
            'title' => str_repeat('あ', 200), 'body' => str_repeat('あ', 5000),
            'target_user_id' => 'unused', 'target_certification_id' => 'unused',
        ]))->assertSessionHas('success');
        $announcement = Announcement::query()->sole();
        $this->assertNull($announcement->target_user_id);
        $this->assertNull($announcement->target_certification_id);
        $this->assertSame(0, $announcement->dispatched_count);
    }

    public function test_create_form_contains_only_eligible_students(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        User::factory()->student()->graduated()->create();

        $this->actingAs($admin)->get(route('admin.announcements.create'))->assertOk()
            ->assertViewHas('students', fn ($users) => $users->modelKeys() === [$student->id]);
    }

    public function test_student_can_read_full_notification_only_for_self_and_mark_it_read(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $other = User::factory()->student()->inProgress()->create();
        $this->actingAs($admin)->post('/admin/announcements', $this->payload());
        $notification = $student->notifications()->sole();

        $this->actingAs($other)->get(route('notifications.show', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($student)->post(route('notifications.markAsRead', $notification->id))
            ->assertRedirect(route('notifications.show', $notification->id));
        $this->get(route('notifications.show', $notification->id))->assertOk()
            ->assertSee('本文の1行目')->assertSee('本文の2行目');
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($admin)->get(route('notifications.show', $notification->id))->assertForbidden();
        $student->update(['status' => UserStatus::Graduated]);
        $this->actingAs($student->fresh())->get(route('notifications.show', $notification->id))->assertOk();
    }

    public function test_notification_body_is_escaped_and_email_contains_full_body(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $this->actingAs($admin)->post('/admin/announcements', $this->payload(['body' => '<script>alert(1)</script>']));
        $notification = $student->notifications()->sole();
        $this->actingAs($student)->get(route('notifications.show', $notification->id))
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
        $message = new AdminAnnouncementNotification(Announcement::query()->sole());
        $message->id = $notification->id;
        $this->assertContains('<script>alert(1)</script>', $message->toMail($student)->introLines);
    }

    public function test_email_failure_keeps_history_and_database_notifications_and_continues_delivery(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        User::factory()->student()->inProgress()->count(2)->create();
        Mail::shouldReceive('mailer')->twice()->andThrow(new \RuntimeException('mail unavailable'));

        $this->actingAs($admin)->post('/admin/announcements', $this->payload())->assertSessionHas('error');

        $this->assertSame(2, Announcement::query()->sole()->dispatched_count);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_management_has_no_edit_delete_or_resend_routes(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $this->actingAs($admin)->post('/admin/announcements', $this->payload());
        $announcement = Announcement::query()->sole();

        $this->get('/admin/announcements/'.$announcement->id.'/edit')->assertNotFound();
        $this->patch('/admin/announcements/'.$announcement->id, ['title' => '変更'])->assertStatus(405);
        $this->delete('/admin/announcements/'.$announcement->id)->assertStatus(405);
        $this->post('/admin/announcements/'.$announcement->id.'/resend')->assertNotFound();
        $this->assertSame('運営からのお知らせ', $announcement->fresh()->title);
    }

    public function test_deleted_student_and_unknown_certification_are_rejected(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();
        $student->delete();

        $this->actingAs($admin)->post('/admin/announcements', $this->payload([
            'target_type' => 'user', 'target_user_id' => $student->id,
        ]))->assertSessionHasErrors('target_user_id');
        $this->post('/admin/announcements', $this->payload([
            'target_type' => 'certification', 'target_certification_id' => (string) Str::ulid(),
        ]))->assertSessionHasErrors('target_certification_id');
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_seeder_creates_three_target_types_and_own_notifications_without_duplicates(): void
    {
        User::factory()->admin()->inProgress()->create(['email' => 'admin@certify-lms.test']);
        $student = User::factory()->student()->inProgress()->create(['email' => 'student@certify-lms.test']);
        Enrollment::factory()->create(['user_id' => $student->id]);

        $this->seed(AnnouncementSeeder::class);
        $this->seed(AnnouncementSeeder::class);

        $this->assertDatabaseCount('announcements', 3);
        $this->assertSame(3, $student->notifications()->count());
        foreach ($student->notifications as $notification) {
            $this->assertSame(route('notifications.show', $notification->id), $notification->data['url']);
        }
    }

    public function test_history_keeps_reverse_dispatch_order_and_paginates(): void
    {
        $admin = User::factory()->admin()->inProgress()->create();
        for ($i = 0; $i < 21; $i++) {
            Announcement::query()->create($this->payload([
                'title' => 'お知らせ'.$i, 'dispatched_at' => now()->subMinutes($i),
            ]));
        }

        $response = $this->actingAs($admin)->get('/admin/announcements');
        $response->assertOk()->assertSeeInOrder(['お知らせ0', 'お知らせ1', 'お知らせ2']);
        $response->assertViewHas('announcements', fn ($items) => $items->count() === 20 && $items->total() === 21);
        $this->get('/admin/announcements?page=2')->assertOk()->assertSee('お知らせ20');
    }
}
