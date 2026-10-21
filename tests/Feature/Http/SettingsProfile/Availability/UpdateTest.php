<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Availability;

use App\Models\CoachAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_update_own_availability(): void
    {
        $coach = User::factory()->coach()->create();
        $availability = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();

        $response = $this->actingAs($coach)->patch(route('settings.availability.update', $availability), [
            'day_of_week' => 3,
            'start_time' => '13:00',
            'end_time' => '18:00',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas('success', '面談可能時間枠を更新しました。');

        $this->assertDatabaseHas('coach_availabilities', [
            'id' => $availability->id,
            'day_of_week' => 3,
            'start_time' => '13:00:00',
            'end_time' => '18:00:00',
            'is_active' => 1,
        ]);
    }

    public function test_coach_cannot_update_other_coachs_availability(): void
    {
        $coach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        $availability = CoachAvailability::factory()->forCoach($other)->monday()->create();

        $response = $this->actingAs($coach)->patch(route('settings.availability.update', $availability), [
            'day_of_week' => 3,
            'start_time' => '13:00',
            'end_time' => '18:00',
            'is_active' => '1',
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('coach_availabilities', [
            'id' => $availability->id,
            'coach_id' => $other->id,
            'day_of_week' => 1,
        ]);
    }

    public function test_validation_fails_when_end_time_before_start_time(): void
    {
        $coach = User::factory()->coach()->create();
        $availability = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();

        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->patch(route('settings.availability.update', $availability), [
                'day_of_week' => 1,
                'start_time' => '17:00',
                'end_time' => '12:00',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors(['end_time']);
        $this->assertDatabaseHas('coach_availabilities', [
            'id' => $availability->id,
            'start_time' => '09:00:00',
        ]);
    }

    public function test_validation_fails_when_time_is_not_on_the_hour(): void
    {
        $coach = User::factory()->coach()->create();
        $availability = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();

        // 更新でも 00 分以外の開始・終了時刻は受け付けない(B-A-05)
        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->patch(route('settings.availability.update', $availability), [
                'day_of_week' => 1,
                'start_time' => '09:30',
                'end_time' => '10:30',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors(['start_time', 'end_time']);
        $this->assertDatabaseHas('coach_availabilities', [
            'id' => $availability->id,
            'start_time' => '09:00:00',
        ]);
    }

    public function test_edit_modal_reopens_with_errors_only_in_submitted_form(): void
    {
        $coach = User::factory()->coach()->create();
        $target = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();
        $other = CoachAvailability::factory()->forCoach($coach)->tuesday()->timeRange('13:00:00', '15:00:00')->create();
        $targetModalId = 'availability-edit-modal-'.$target->id;
        $otherModalId = 'availability-edit-modal-'.$other->id;

        // 入力エラーで戻ったとき、送信した編集モーダルだけを開き、他のフォームに入力値とエラーを出さない(B-A-05)
        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->followingRedirects()
            ->patch(route('settings.availability.update', $target), [
                '_modal' => $targetModalId,
                'day_of_week' => 1,
                'start_time' => '09:30',
                'end_time' => '10:30',
                'is_active' => '1',
            ]);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-auto-open', $this->modalOpenTag($html, $targetModalId));
        $this->assertStringNotContainsString('data-auto-open', $this->modalOpenTag($html, 'availability-create-modal'));
        $this->assertStringNotContainsString('data-auto-open', $this->modalOpenTag($html, $otherModalId));
        $this->assertSame(1, substr_count($html, '開始時刻は 00 分単位(例: 09:00)で指定してください。'));

        $this->assertStringContainsString('value="09:30"', $this->modalHtml($html, $targetModalId));
        $this->assertStringContainsString('value="13:00"', $this->modalHtml($html, $otherModalId));
        $this->assertStringNotContainsString('value="09:30"', $this->modalHtml($html, 'availability-create-modal'));
        $this->assertDatabaseHas('coach_availabilities', [
            'id' => $target->id,
            'start_time' => '09:00:00',
        ]);
    }

    public function test_student_is_forbidden(): void
    {
        $coach = User::factory()->coach()->create();
        $availability = CoachAvailability::factory()->forCoach($coach)->monday()->create();
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->patch(route('settings.availability.update', $availability), [
            'day_of_week' => 3,
            'start_time' => '13:00',
            'end_time' => '18:00',
            'is_active' => '1',
        ]);

        $response->assertForbidden();
    }

    /**
     * ページ HTML から指定 id のモーダル部分(次のモーダルの手前まで)を切り出す。
     */
    private function modalHtml(string $html, string $modalId): string
    {
        $start = strpos($html, 'id="'.$modalId.'"');
        $this->assertNotFalse($start, "モーダル {$modalId} が描画されていない");
        $own = strpos($html, 'role="dialog"', $start);
        $next = strpos($html, 'role="dialog"', $own + 1);

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    private function modalOpenTag(string $html, string $modalId): string
    {
        $this->assertSame(1, preg_match('/<div\s+id="'.preg_quote($modalId, '/').'"[^>]*>/', $html, $m));

        return $m[0];
    }
}
