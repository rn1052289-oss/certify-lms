<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\UserAvatarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reuses_avatar_file_when_fixed_student_is_recreated(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $this->seed(UserAvatarSeeder::class);
        $url = $student->fresh()->avatar_url;

        $student->forceDelete();
        $replacement = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $this->seed(UserAvatarSeeder::class);

        $this->assertSame($url, $replacement->fresh()->avatar_url);
        $this->assertEmpty(Storage::disk('public')->allFiles('avatars'));
    }

    public function test_seeds_users_with_and_without_displayable_avatar(): void
    {
        Storage::fake('public');

        $this->seed();

        $student = User::query()->where('email', 'student@certify-lms.test')->firstOrFail();
        $this->assertNotNull($student->avatar_url);
        $this->assertSame('/images/seed-avatar.png', $student->avatar_url);
        $this->assertFileExists(public_path($student->avatar_url));
        $this->assertSame('image/png', getimagesize(public_path($student->avatar_url))['mime']);
        $this->assertTrue(User::query()->whereNull('avatar_url')->exists());

        $this->actingAs($student)->get(route('settings.profile.edit'))
            ->assertOk()->assertSee($student->avatar_url);

        $this->seed(UserAvatarSeeder::class);
        $this->assertSame($student->avatar_url, $student->fresh()->avatar_url);
        $this->assertEmpty(Storage::disk('public')->allFiles('avatars'));
    }
}
