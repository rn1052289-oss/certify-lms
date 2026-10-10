<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\UserAvatar;

use App\Models\User;
use App\UseCases\UserAvatar\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DestroyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_avatar_does_not_delete_another_users_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $otherPath = "avatars/{$coach->id}/original.png";
        Storage::disk('public')->put($otherPath, 'existing file');

        $coach->update(['avatar_url' => "/storage/{$otherPath}"]);
        $student->update(['avatar_url' => "/storage/{$otherPath}"]);

        $action = $this->app->make(DestroyAction::class);
        $action($student);

        $this->assertNull($student->fresh()->avatar_url);
        $this->assertSame("/storage/{$otherPath}", $coach->fresh()->avatar_url);
        Storage::disk('public')->assertExists($otherPath);
    }
}
