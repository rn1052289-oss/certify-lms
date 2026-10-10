<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_tab_displays_change_form(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->get(route('settings.profile.edit', ['tab' => 'password']))
            ->assertStatus(200)
            ->assertSee('現在のパスワード')
            ->assertSee('新しいパスワード')
            ->assertSee('パスワードを変更する');
    }

    public function test_student_can_change_password(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('settings.profile.edit', ['tab' => 'password']));
        $response->assertSessionHas('success', 'パスワードを変更しました。');

        $this->assertNotSame($originalHash, $student->fresh()->password);
        $this->assertTrue(Hash::check('new-password-123', $student->fresh()->password));
        $this->assertFalse(Hash::check('password', $student->fresh()->password));
    }

    public function test_coach_admin_and_graduated_student_can_change_password(): void
    {
        $users = [
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->student()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $response = $this->actingAs($user)->put(route('settings.password.update'), [
                'current_password' => 'password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ]);

            $response->assertStatus(302);
            $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        }
    }

    public function test_eight_character_password_is_accepted(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'abcd1234',
            'password_confirmation' => 'abcd1234',
        ]);

        $response->assertStatus(302);
        $this->assertTrue(Hash::check('abcd1234', $student->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors([
            'current_password' => '現在のパスワードが正しくありません。',
        ], null, 'updatePassword');
        $this->assertSame($originalHash, $student->fresh()->password);
    }

    public function test_current_password_is_required(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertSessionHasErrors(['current_password'], null, 'updatePassword');
        $this->assertSame($originalHash, $student->fresh()->password);
    }

    public function test_password_shorter_than_eight_characters_is_rejected(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ]);

        $response->assertSessionHasErrors(['password'], null, 'updatePassword');
        $this->assertSame($originalHash, $student->fresh()->password);
    }

    public function test_password_confirmation_must_match(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors(['password'], null, 'updatePassword');
        $this->assertSame($originalHash, $student->fresh()->password);
    }

    public function test_new_password_is_required(): void
    {
        $student = User::factory()->student()->create();
        $originalHash = $student->password;

        $response = $this->actingAs($student)->put(route('settings.password.update'), [
            'current_password' => 'password',
        ]);

        $response->assertSessionHasErrors(['password'], null, 'updatePassword');
        $this->assertSame($originalHash, $student->fresh()->password);
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->put(route('settings.password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('/login');
    }
}
