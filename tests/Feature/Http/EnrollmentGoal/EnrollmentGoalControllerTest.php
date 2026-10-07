<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 個人学習目標 CRUD・達成状態変更・閲覧権限の HTTP 統合テスト。
 */
class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => '過去問5年分を解く',
            'description' => '試験までに直近5年分の過去問を一通り解く。',
            'target_date' => now()->addMonth()->toDateString(),
        ], $override);
    }

    public function test_owner_student_can_create_goal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $this->payload());

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => '過去問5年分を解く',
            'description' => '試験までに直近5年分の過去問を一通り解く。',
            'target_date' => now()->addMonth()->toDateString(),
            'achieved_at' => null,
        ]);
    }

    public function test_owner_student_can_create_goal_without_optional_values(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)
            ->post(
                route('enrollments.goals.store', $enrollment),
                $this->payload(['description' => null, 'target_date' => null]),
            );

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'description' => null,
            'target_date' => null,
        ]);
    }

    public function test_owner_student_can_create_goal_with_past_target_date(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $pastDate = now()->subMonth()->toDateString();

        $response = $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $this->payload(['target_date' => $pastDate]));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'target_date' => $pastDate,
        ]);
    }

    public function test_owner_student_can_create_goal_with_maximum_length_values(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)
            ->post(
                route('enrollments.goals.store', $enrollment),
                $this->payload(['title' => str_repeat('a', 100), 'description' => str_repeat('a', 1000)]),
            );

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enrollment_goals', [
            'enrollment_id' => $enrollment->id,
            'title' => str_repeat('a', 100),
            'description' => str_repeat('a', 1000),
        ]);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidValueProvider')]
    public function test_owner_student_cannot_create_goal_with_invalid_value(
        array $override,
        string $errorField,
    ): void {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $this->payload($override));

        $response->assertStatus(302);
        $response->assertSessionHasErrors($errorField);

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'title required' => [['title' => ''], 'title'],
            'title over maximum' => [['title' => str_repeat('a', 101)], 'title'],
            'description over maximum' => [['description' => str_repeat('a', 1001)], 'description'],
            'invalid target date' => [['target_date' => 'invalid-date'], 'target_date'],
        ];
    }

    public function test_other_student_cannot_create_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        $response = $this->actingAs($otherStudent)
            ->post(route('enrollments.goals.store', $enrollment), $this->payload());

        $response->assertStatus(403);

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    public function test_owner_student_can_view_edit_form(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '編集前の目標',
        ]);

        $response = $this->actingAs($student)
            ->get(route('enrollment-goals.edit', $goal));

        $response->assertStatus(200);
        $response->assertViewIs('enrollment-goal.edit');
        $response->assertViewHas('goal');
        $response->assertSee('編集前の目標');
    }

    public function test_other_student_cannot_view_edit_form(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($otherStudent)
            ->get(route('enrollment-goals.edit', $goal));

        $response->assertStatus(403);
    }

    public function test_owner_student_can_update_goal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '更新前の目標',
            'description' => '更新前の詳細',
            'target_date' => now()->addMonth()->toDateString(),
        ]);

        $pastDate = now()->subWeek()->toDateString();

        $response = $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), [
                'title' => '更新後の目標',
                'description' => '更新後の詳細',
                'target_date' => $pastDate,
            ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'enrollment_id' => $enrollment->id,
            'title' => '更新後の目標',
            'description' => '更新後の詳細',
            'target_date' => $pastDate,
        ]);
    }

    public function test_update_validates_input(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '変更されない目標',
        ]);

        $response = $this->actingAs($student)
            ->patch(route('enrollment-goals.update', $goal), [
                'title' => str_repeat('a', 101),
                'description' => str_repeat('a', 1001),
                'target_date' => 'invalid-date',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors([
            'title',
            'description',
            'target_date',
        ]);

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '変更されない目標',
        ]);
    }

    public function test_other_student_cannot_update_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '元の目標',
        ]);

        $response = $this->actingAs($otherStudent)
            ->patch(
                route('enrollment-goals.update', $goal),
                $this->payload(['title' => '不正に更新した目標']),
            );

        $response->assertStatus(403);

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'title' => '元の目標',
        ]);
    }

    public function test_owner_student_can_delete_goal_physically(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($student)
            ->delete(route('enrollment-goals.destroy', $goal));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertDatabaseMissing('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_other_student_cannot_delete_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($otherStudent)
            ->delete(route('enrollment-goals.destroy', $goal));

        $response->assertStatus(403);

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    public function test_owner_student_can_mark_goal_as_achieved(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'achieved_at' => null,
        ]);

        $response = $this->actingAs($student)
            ->post(route('enrollment-goals.markAchieved', $goal));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_mark_goal_as_achieved(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'achieved_at' => null,
        ]);

        $response = $this->actingAs($otherStudent)
            ->post(route('enrollment-goals.markAchieved', $goal));

        $response->assertStatus(403);

        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_owner_student_can_unmark_achieved_goal(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();

        $response = $this->actingAs($student)
            ->delete(route('enrollment-goals.unmarkAchieved', $goal));

        $response->assertStatus(302);
        $response->assertRedirect(route('enrollments.show', $enrollment));

        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_other_student_cannot_unmark_achieved_goal(): void
    {
        $owner = User::factory()->student()->inProgress()->create();
        $otherStudent = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($owner)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();

        $response = $this->actingAs($otherStudent)
            ->delete(route('enrollment-goals.unmarkAchieved', $goal));

        $response->assertStatus(403);

        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_admin_can_view_goal_on_enrollment_detail_as_read_only(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '管理者閲覧用の目標',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('enrollments.show', $enrollment));

        $response->assertStatus(200);
        $response->assertSee('管理者閲覧用の目標');
        $response->assertDontSee('目標を追加');
        $response->assertDontSee(route('enrollment-goals.edit', $goal), false);
    }

    public function test_assigned_coach_can_view_goal_on_enrollment_detail_as_read_only(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::create([
            'id' => (string) Str::ulid(),
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create([
            'title' => '担当コーチ閲覧用の目標',
        ]);

        $response = $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment));

        $response->assertStatus(200);
        $response->assertSee('担当コーチ閲覧用の目標');
        $response->assertDontSee('目標を追加');
        $response->assertDontSee(route('enrollment-goals.edit', $goal), false);
    }

    public function test_unassigned_coach_cannot_view_enrollment_goal(): void
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($coach)
            ->get(route('enrollments.show', $enrollment));

        $response->assertStatus(403);
    }

    #[DataProvider('staffRoleProvider')]
    public function test_staff_cannot_modify_goal(string $role): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $user = User::factory()->{$role}()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $response = $this->actingAs($user)
            ->patch(route('enrollment-goals.update', $goal), $this->payload());

        $response->assertStatus(403);

        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffRoleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'coach' => ['coach'],
        ];
    }

    #[DataProvider('inactiveStudentStateProvider')]
    public function test_inactive_student_cannot_create_goal(string $state): void
    {
        $factory = User::factory()->student();
        $student = $factory->{$state}()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();

        $response = $this->actingAs($student)
            ->post(route('enrollments.goals.store', $enrollment), $this->payload());

        $response->assertStatus(403);

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inactiveStudentStateProvider(): array
    {
        return [
            'graduated' => ['graduated'],
            'withdrawn' => ['withdrawn'],
            'invited' => ['invited'],
        ];
    }
}
