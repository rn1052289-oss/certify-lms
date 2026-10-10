<?php

declare(strict_types=1);

namespace Tests\Unit\UseCases\UserAvatar;

use App\Models\User;
use App\UseCases\UserAvatar\StoreAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_uploaded_file_when_user_cannot_be_found(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();
        $student->delete();

        $action = $this->app->make(StoreAction::class);

        try {
            $action($student, UploadedFile::fake()->image('portrait.png'));
            $this->fail('ユーザーが見つからないため例外が発生するはずです。');
        } catch (ModelNotFoundException) {
            $this->assertEmpty(Storage::disk('public')->allFiles("avatars/{$student->id}"));
        }
    }

    public function test_replacement_does_not_delete_another_users_file(): void
    {
        Storage::fake('public');
        $student = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();

        $otherPath = "avatars/{$coach->id}/original.png";
        Storage::disk('public')->put($otherPath, 'existing file');
        $student->update(['avatar_url' => "/storage/{$otherPath}"]);

        $action = $this->app->make(StoreAction::class);
        $action($student, UploadedFile::fake()->image('portrait.png'));

        $this->assertNotSame("/storage/{$otherPath}", $student->fresh()->avatar_url);
        Storage::disk('public')->assertExists($otherPath);
    }
}
