<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoal Model のリレーション・達成判定・表示順を検証する。
 */
class EnrollmentGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_relation_returns_parent_enrollment(): void
    {
        $enrollment = Enrollment::factory()->create();

        $goal = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create();

        $this->assertTrue($goal->enrollment->is($enrollment));
    }

    public function test_is_achieved_returns_false_when_achieved_at_is_null(): void
    {
        $goal = EnrollmentGoal::factory()->create([
            'achieved_at' => null,
        ]);

        $this->assertFalse($goal->isAchieved());
    }

    public function test_is_achieved_returns_true_when_achieved_at_exists(): void
    {
        $goal = EnrollmentGoal::factory()->achieved()->create();

        $this->assertTrue($goal->isAchieved());
    }

    public function test_display_order_places_unachieved_goals_by_target_date_then_without_date_then_achieved(): void
    {
        $enrollment = Enrollment::factory()->create();

        $sameDateOlder = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create([
                'title' => '同一期日・古い目標',
                'target_date' => now()->addDay()->toDateString(),
                'achieved_at' => null,
                'created_at' => now()->subDays(2),
            ]);

        $sameDateNewer = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create([
                'title' => '同一期日・新しい目標',
                'target_date' => now()->addDay()->toDateString(),
                'achieved_at' => null,
                'created_at' => now()->subDay(),
            ]);

        $later = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->create([
                'title' => '期日が後の目標',
                'target_date' => now()->addDays(5)->toDateString(),
                'achieved_at' => null,
            ]);

        $withoutDate = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->withoutTargetDate()
            ->create([
                'title' => '期日なしの目標',
                'achieved_at' => null,
            ]);

        $achieved = EnrollmentGoal::factory()
            ->forEnrollment($enrollment)
            ->achieved()
            ->create([
                'title' => '達成済み目標',
                'target_date' => now()->subDay()->toDateString(),
            ]);

        $results = EnrollmentGoal::query()
            ->where('enrollment_id', $enrollment->id)
            ->displayOrder()
            ->get();

        $this->assertSame([
            $sameDateNewer->id,
            $sameDateOlder->id,
            $later->id,
            $withoutDate->id,
            $achieved->id,
        ], $results->pluck('id')->all());
    }
}
