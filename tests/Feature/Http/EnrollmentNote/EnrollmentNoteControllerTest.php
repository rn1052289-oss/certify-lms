<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講生メモの CRUD・入力検証・閲覧権限の HTTP 統合テスト。
 */
class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function assignedEnrollment(User $coach): Enrollment
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);

        return Enrollment::factory()->for($student)->for($certification)->learning()->create();
    }

    public function test_assigned_coach_can_create_note_as_author(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        $response = $this->actingAs($coach)->post(route('enrollments.notes.store', $enrollment), [
            'body' => '次回は模試の復習を行う。',
            'author_user_id' => $otherCoach->id,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '次回は模試の復習を行う。',
        ]);
        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_admin_can_create_note(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($admin)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '管理者からの連絡メモ。']);

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $admin->id,
            'body' => '管理者からの連絡メモ。',
        ]);
    }

    public function test_coach_can_create_multiple_notes_on_same_enrollment(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '1件目のメモ。'])
            ->assertStatus(302);

        $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '2件目のメモ。'])
            ->assertStatus(302);

        $this->assertDatabaseCount('enrollment_notes', 2);
    }

    public function test_unassigned_coach_cannot_create_note(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        $response = $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '担当外のメモ。']);

        $response->assertStatus(403);
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_student_cannot_create_note_or_see_note_section(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'body' => '受講生には非公開のメモ。',
        ]);

        $response = $this->actingAs($student)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '受講生の投稿。']);

        $response->assertStatus(403);

        $response = $this->actingAs($student)->get(route('enrollments.show', $enrollment));

        $response->assertStatus(200);
        $response->assertDontSee('コーチメモ');
        $response->assertDontSee('受講生には非公開のメモ。');
        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_create_rejects_empty_body(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        $response = $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => '']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_create_rejects_body_over_2000_characters(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        $response = $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => str_repeat('a', 2001)]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_create_rejects_non_string_body(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        $response = $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => ['invalid']]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');
        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_create_accepts_body_with_2000_characters(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $body = str_repeat('a', 2000);

        $response = $this->actingAs($coach)
            ->post(route('enrollments.notes.store', $enrollment), ['body' => $body]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $enrollment->id,
            'body' => $body,
        ]);
    }

    public function test_assigned_coach_can_view_notes_in_newest_first_order(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $enrollment->certification_id,
            'user_id' => $otherCoach->id,
        ]);

        $oldNote = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '先に記録したメモ。',
            'created_at' => now()->subDay(),
        ]);

        $newNote = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $otherCoach->id,
            'body' => '後から記録したメモ。',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        $response->assertStatus(200);
        $response->assertSee('コーチメモ');
        $response->assertSeeInOrder(['後から記録したメモ。', '先に記録したメモ。']);
        $response->assertSee(route('enrollment-notes.edit', $oldNote), false);
        $response->assertDontSee(route('enrollment-notes.edit', $newNote), false);
    }

    public function test_admin_can_view_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'body' => '管理者が閲覧するメモ。',
        ]);

        $response = $this->actingAs($admin)->get(route('enrollments.show', $enrollment));

        $response->assertStatus(200);
        $response->assertSee('コーチメモ');
        $response->assertSee('管理者が閲覧するメモ。');
    }

    public function test_assigned_coach_can_view_own_note_edit_form(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '編集前のメモ。',
        ]);

        $response = $this->actingAs($coach)->get(route('enrollment-notes.edit', $note));

        $response->assertStatus(200);
        $response->assertViewIs('enrollment-note.edit');
        $response->assertViewHas('note');
        $response->assertSee('編集前のメモ。');
    }

    public function test_assigned_coach_can_update_own_note(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '更新前のメモ。',
        ]);

        $response = $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新後のメモ。']);

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '更新後のメモ。',
        ]);
    }

    public function test_update_rejects_empty_body(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '変更されないメモ。',
        ]);

        $response = $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '変更されないメモ。',
        ]);
    }

    public function test_update_rejects_body_over_2000_characters(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '変更されないメモ。',
        ]);

        $response = $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => str_repeat('a', 2001)]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '変更されないメモ。',
        ]);
    }

    public function test_update_rejects_non_string_body(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
            'body' => '変更されないメモ。',
        ]);

        $response = $this->actingAs($coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => ['invalid']]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('body');

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '変更されないメモ。',
        ]);
    }

    public function test_assigned_coach_cannot_modify_other_coachs_note(): void
    {
        $coach = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $enrollment->certification_id,
            'user_id' => $otherCoach->id,
        ]);

        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $otherCoach->id,
            'body' => '他のコーチのメモ。',
        ]);

        $this->actingAs($coach)->get(route('enrollment-notes.edit', $note))->assertStatus(403);
        $this->actingAs($coach)->patch(route('enrollment-notes.update', $note), ['body' => '不正更新。'])->assertStatus(403);
        $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note))->assertStatus(403);

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '他のコーチのメモ。',
        ]);
    }

    public function test_admin_can_update_other_users_note(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'body' => 'コーチのメモ。',
        ]);

        $response = $this->actingAs($admin)
            ->patch(route('enrollment-notes.update', $note), ['body' => '管理者が更新したメモ。']);

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_notes', [
            'id' => $note->id,
            'body' => '管理者が更新したメモ。',
        ]);
    }

    public function test_assigned_coach_can_delete_own_note_physically(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = $this->assignedEnrollment($coach);
        $note = EnrollmentNote::factory()->create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $coach->id,
        ]);

        $response = $this->actingAs($coach)->delete(route('enrollment-notes.destroy', $note));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_admin_can_delete_other_users_note(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);

        $response = $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $note));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_student_cannot_modify_note(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $note = EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);

        $this->actingAs($student)->get(route('enrollment-notes.edit', $note))->assertStatus(403);
        $this->actingAs($student)->patch(route('enrollment-notes.update', $note), ['body' => '不正更新。'])->assertStatus(403);
        $this->actingAs($student)->delete(route('enrollment-notes.destroy', $note))->assertStatus(403);

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }

    public function test_unassigned_coach_cannot_view_enrollment_notes(): void
    {
        $coach = User::factory()->coach()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);

        $response = $this->actingAs($coach)->get(route('enrollments.show', $enrollment));

        $response->assertStatus(403);
    }

    public function test_notes_of_soft_deleted_enrollment_cannot_be_operated(): void
    {
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        $note = EnrollmentNote::factory()->create(['enrollment_id' => $enrollment->id]);
        $enrollment->delete();

        $this->actingAs($admin)->get(route('enrollment-notes.edit', $note))->assertStatus(403);
        $this->actingAs($admin)->patch(route('enrollment-notes.update', $note), ['body' => '不正更新。'])->assertStatus(403);
        $this->actingAs($admin)->delete(route('enrollment-notes.destroy', $note))->assertStatus(403);
        $this->actingAs($admin)->post(route('enrollments.notes.store', $enrollment), ['body' => '不正作成。'])->assertStatus(404);

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
    }
}
