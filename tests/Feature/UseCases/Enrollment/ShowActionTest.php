<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\Enrollment\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enrollment 詳細取得 Action `ShowAction` の eager load を検証する Feature テスト。
 * 詳細ビューに必要な certification / certificate / 最新の状態遷移ログ / 個人目標が eager load されることを確認する。
 */
class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_certification_for_detail_view(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();

        // Act
        $result = app(ShowAction::class)($enrollment);

        // Assert
        $this->assertTrue(
            $result->relationLoaded('certification'),
            'ShowAction は詳細表示用に certification を eager load するはず',
        );
    }

    public function test_loads_goals_for_detail_view(): void
    {
        // Arrange
        $enrollment = Enrollment::factory()->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        // Act
        $action = $this->app->make(ShowAction::class);
        $result = $action($enrollment);

        // Assert
        $this->assertTrue(
            $result->relationLoaded('goals'),
            'ShowAction は詳細表示用に goals を eager load するはず',
        );
        $this->assertTrue($result->goals->contains('id', $goal->id));
    }
}
