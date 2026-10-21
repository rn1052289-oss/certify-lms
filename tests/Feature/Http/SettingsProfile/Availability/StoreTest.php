<?php

declare(strict_types=1);

namespace Tests\Feature\Http\SettingsProfile\Availability;

use App\Models\CoachAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_can_create_availability(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)->post(route('settings.availability.store'), [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('settings.availability.index'));
        $response->assertSessionHas('success', '面談可能時間枠を追加しました。');

        $this->assertDatabaseHas('coach_availabilities', [
            'coach_id' => $coach->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_active' => 1,
        ]);
    }

    public function test_coach_can_create_availability_with_is_active_false_when_checkbox_unchecked(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)->post(route('settings.availability.store'), [
            'day_of_week' => 2,
            'start_time' => '14:00',
            'end_time' => '17:00',
        ]);

        $this->assertDatabaseHas('coach_availabilities', [
            'coach_id' => $coach->id,
            'day_of_week' => 2,
            'is_active' => 0,
        ]);
    }

    public function test_multiple_overlapping_slots_on_same_day_are_allowed(): void
    {
        $coach = User::factory()->coach()->create();

        $this->actingAs($coach)->post(route('settings.availability.store'), [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_active' => '1',
        ]);
        $this->actingAs($coach)->post(route('settings.availability.store'), [
            'day_of_week' => 1,
            'start_time' => '10:00',
            'end_time' => '15:00',
            'is_active' => '1',
        ]);

        $this->assertSame(2, $coach->coachAvailabilities()->count());
    }

    public function test_validation_fails_when_end_time_is_before_start_time(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->post(route('settings.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '17:00',
                'end_time' => '09:00',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors(['end_time']);
        $this->assertSame(0, $coach->coachAvailabilities()->count());
    }

    public function test_validation_fails_when_day_of_week_is_out_of_range(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->post(route('settings.availability.store'), [
                'day_of_week' => 7,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors(['day_of_week']);
    }

    public function test_validation_fails_when_start_time_format_is_invalid(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->post(route('settings.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '9 am',
                'end_time' => '12:00',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors(['start_time']);
    }

    public function test_validation_fails_when_time_is_not_on_the_hour(): void
    {
        $coach = User::factory()->coach()->create();

        // 面談は毎時 00 分開始のため、09:30 開始・17:30 終了の枠は登録できない(B-A-05)
        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->post(route('settings.availability.store'), [
                'day_of_week' => 1,
                'start_time' => '09:30',
                'end_time' => '17:30',
                'is_active' => '1',
            ]);

        $response->assertSessionHasErrors([
            'start_time' => '開始時刻は 00 分単位(例: 09:00)で指定してください。',
            'end_time' => '終了時刻は 00 分単位(例: 17:00)で指定してください。',
        ]);
        $this->assertSame(0, $coach->coachAvailabilities()->count());
    }

    public function test_create_modal_reopens_with_errors_only_in_submitted_form(): void
    {
        $coach = User::factory()->coach()->create();
        $existing = CoachAvailability::factory()->forCoach($coach)->monday()->morning()->create();

        // 入力エラーで戻ったとき、追加モーダルだけを開き、入力値とエラーは追加フォームにだけ戻す(B-A-05)
        $response = $this->actingAs($coach)
            ->from(route('settings.availability.index'))
            ->followingRedirects()
            ->post(route('settings.availability.store'), [
                '_modal' => 'availability-create-modal',
                'day_of_week' => 4,
                'start_time' => '09:30',
                'end_time' => '10:30',
                'is_active' => '1',
            ]);

        $response->assertOk();
        $html = $response->getContent();
        $editModalId = 'availability-edit-modal-'.$existing->id;

        $this->assertStringContainsString('data-auto-open', $this->modalOpenTag($html, 'availability-create-modal'));
        $this->assertStringNotContainsString('data-auto-open', $this->modalOpenTag($html, $editModalId));
        $this->assertSame(1, substr_count($html, '開始時刻は 00 分単位(例: 09:00)で指定してください。'));

        $createModal = $this->modalHtml($html, 'availability-create-modal');
        $this->assertStringContainsString('value="09:30"', $createModal);
        $this->assertStringContainsString('開始時刻は 00 分単位', $createModal);

        $editModal = $this->modalHtml($html, $editModalId);
        $this->assertStringContainsString('value="09:00"', $editModal);
        $this->assertStringNotContainsString('value="09:30"', $editModal);
        $this->assertSame(1, $coach->coachAvailabilities()->count());
    }

    public function test_student_is_forbidden(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)->post(route('settings.availability.store'), [
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
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
