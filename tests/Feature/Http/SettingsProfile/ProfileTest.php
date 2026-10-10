<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_and_graduated_student_can_view_profile(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->student()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('settings.profile.edit'))
                ->assertStatus(200)
                ->assertSee($user->email);
        }
    }

    public function test_guest_cannot_view_profile(): void
    {
        $this->get(route('settings.profile.edit'))->assertRedirect('/login');
    }

    public function test_student_can_update_name_and_bio(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '受講太郎',
            'email' => $student->email,
            'bio' => '資格取得を目指しています。',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => '受講太郎',
            'bio' => '資格取得を目指しています。',
        ]);
    }

    public function test_coach_can_update_meeting_url(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $response->assertStatus(302);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $coach->fresh()->meeting_url);
    }

    public function test_coach_can_clear_meeting_url(): void
    {
        $coach = User::factory()->coach()->create([
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => '',
        ]);

        $response->assertStatus(302);
        $this->assertNull($coach->fresh()->meeting_url);
    }

    public function test_non_coach_cannot_update_meeting_url(): void
    {
        $users = [
            User::factory()->student()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($users as $user) {
            $response = $this->actingAs($user)->patch(route('settings.profile.update'), [
                'name' => $user->name,
                'meeting_url' => 'https://example.test/meeting',
            ]);

            $response->assertSessionHasErrors('meeting_url');
            $this->assertNull($user->fresh()->meeting_url);
        }
    }

    public function test_name_is_required(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '',
        ]);

        $response->assertSessionHasErrors(['name' => '氏名を入力してください。']);
    }

    public function test_name_and_bio_length_limits_are_enforced(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => str_repeat('あ', 51),
            'bio' => str_repeat('あ', 1001),
        ]);

        $response->assertSessionHasErrors(['name', 'bio']);
    }

    public function test_invalid_coach_meeting_url_is_rejected(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => 'invalid-url',
        ]);

        $response->assertSessionHasErrors('meeting_url');
    }

    public function test_coach_meeting_url_cannot_exceed_500_characters(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->patch(route('settings.profile.update'), [
            'name' => $coach->name,
            'meeting_url' => 'https://example.test/'.str_repeat('a', 500),
        ]);

        $response->assertSessionHasErrors('meeting_url');
    }

    public function test_email_cannot_be_changed_from_profile(): void
    {
        $student = User::factory()->student()->create();
        $originalEmail = $student->email;

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => $student->name,
            'email' => 'changed@example.test',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame($originalEmail, $student->fresh()->email);
    }

    public function test_fortify_profile_endpoint_cannot_change_email(): void
    {
        $student = User::factory()->student()->create();
        $originalEmail = $student->email;

        $response = $this->actingAs($student)->put('/user/profile-information', [
            'name' => $student->name,
            'email' => 'changed@example.test',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame($originalEmail, $student->fresh()->email);
    }

    public function test_role_and_status_cannot_be_changed_from_profile(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.profile.update'), [
            'name' => '変更後の氏名',
            'role' => UserRole::Admin->value,
            'status' => UserStatus::Graduated->value,
        ]);

        $response->assertStatus(302);
        $this->assertSame(UserRole::Student, $student->fresh()->role);
        $this->assertSame(UserStatus::InProgress, $student->fresh()->status);
    }
}
