<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentNote Model のリレーションを検証する。
 */
class EnrollmentNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        $enrollment = Enrollment::factory()->create();
        $note = EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);

        $this->assertTrue($note->enrollment->is($enrollment));
    }

    public function test_author_relation_returns_author_user(): void
    {
        $coach = User::factory()->coach()->create();
        $note = EnrollmentNote::factory()->create(['author_user_id' => $coach->id]);

        $this->assertTrue($note->author->is($coach));
    }

    public function test_enrollment_notes_relation_returns_child_notes(): void
    {
        $enrollment = Enrollment::factory()->create();
        $note = EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);

        $this->assertTrue($enrollment->notes->contains('id', $note->id));
    }
}
