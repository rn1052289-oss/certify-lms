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

    public function test_seeds_users_with_and_without_displayable_avatar(): void
    {
        Storage::fake('public');

        $this->seed();

        $student = User::query()->where('email', 'student@certify-lms.test')->firstOrFail();
        $this->assertNotNull($student->avatar_url);
        $this->assertStringStartsWith("/storage/avatars/{$student->id}/", $student->avatar_url);

        $path = substr($student->avatar_url, strlen('/storage/'));
        Storage::disk('public')->assertExists($path);
        $this->assertSame('image/png', getimagesizefromstring(Storage::disk('public')->get($path))['mime']);
        $this->assertTrue(User::query()->whereNull('avatar_url')->exists());

        $this->actingAs($student)->get(route('settings.profile.edit'))
            ->assertOk()->assertSee($student->avatar_url);

        $this->seed(UserAvatarSeeder::class);
        $this->assertSame($student->avatar_url, $student->fresh()->avatar_url);
        $this->assertCount(1, Storage::disk('public')->allFiles('avatars'));
    }
}
