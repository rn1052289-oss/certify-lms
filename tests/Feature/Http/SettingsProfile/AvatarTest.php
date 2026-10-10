<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_upload_and_display_avatar(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('portrait.png'),
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('settings.profile.edit'));

        $url = $student->fresh()->avatar_url;
        $this->assertNotNull($url);
        $this->assertStringStartsWith("/storage/avatars/{$student->id}/", $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));

        $student->refresh();

        $this->get(route('settings.profile.edit'))->assertStatus(200)->assertSee($url);
    }

    public function test_coach_admin_and_graduated_student_can_upload_avatar(): void
    {
        Storage::fake('public');

        $users = [
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
            User::factory()->student()->graduated()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('portrait.png'),
            ])->assertStatus(302);

            $url = $user->fresh()->avatar_url;
            $this->assertNotNull($url);
            Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        }
    }

    public function test_jpeg_and_webp_images_are_accepted(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        foreach (['portrait.jpg', 'portrait.webp'] as $filename) {
            $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
                'avatar' => UploadedFile::fake()->image($filename),
            ]);

            $response->assertStatus(302);
            $url = $student->fresh()->avatar_url;
            $this->assertNotNull($url);
            Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        }
    }

    public function test_avatar_at_two_megabyte_limit_is_accepted(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('limit.png')->size(2048),
        ]);

        $response->assertStatus(302);
        $this->assertNotNull($student->fresh()->avatar_url);
    }

    public function test_replacing_avatar_removes_previous_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('first.png'),
        ])->assertStatus(302);

        $oldUrl = $student->fresh()->avatar_url;
        $this->assertNotNull($oldUrl);
        $oldPath = substr($oldUrl, strlen('/storage/'));
        Storage::disk('public')->assertExists($oldPath);

        $this->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('second.png'),
        ])->assertStatus(302);

        $newUrl = $student->fresh()->avatar_url;
        $this->assertNotNull($newUrl);
        $this->assertNotSame($oldUrl, $newUrl);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists(substr($newUrl, strlen('/storage/')));
    }

    public function test_deleting_avatar_removes_file_and_clears_url(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('portrait.png'),
        ])->assertStatus(302);

        $oldUrl = $student->fresh()->avatar_url;
        $this->assertNotNull($oldUrl);

        $response = $this->delete(route('settings.avatar.destroy'));

        $response->assertStatus(302);
        $response->assertRedirect(route('settings.profile.edit'));
        $this->assertNull($student->fresh()->avatar_url);
        Storage::disk('public')->assertMissing(substr($oldUrl, strlen('/storage/')));
        $this->get(route('settings.profile.edit'))->assertStatus(200)->assertDontSee($oldUrl);
    }

    public function test_deleting_own_avatar_does_not_affect_another_user(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('student.png'),
        ])->assertStatus(302);

        $this->actingAs($coach)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('coach.png'),
        ])->assertStatus(302);

        $coachUrl = $coach->fresh()->avatar_url;
        $this->assertNotNull($coachUrl);

        $this->actingAs($student)->delete(route('settings.avatar.destroy'))->assertStatus(302);

        $this->assertNull($student->fresh()->avatar_url);
        $this->assertSame($coachUrl, $coach->fresh()->avatar_url);
        Storage::disk('public')->assertExists(substr($coachUrl, strlen('/storage/')));
    }

    public function test_avatar_file_is_required(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), []);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->create('document.pdf', 50, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_oversized_avatar_is_rejected(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('large.png')->size(2049),
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($student->fresh()->avatar_url);
    }

    public function test_guest_cannot_upload_or_delete_avatar(): void
    {
        $this->post(route('settings.avatar.store'), [])->assertRedirect('/login');
        $this->delete(route('settings.avatar.destroy'))->assertRedirect('/login');
    }
}
