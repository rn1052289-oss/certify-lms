<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 予約確定時のコーチ割当で、60 分間すべて対応できるコーチだけが選ばれることを確認する(B-A-05)。
 *
 * 00 分以外の枠は登録・更新で拒否するようになったが、修正前に登録済の枠が残りうるため、
 * テストでは factory で直接 09:30〜10:30 の枠を作って割当判定を確認する。
 */
class MeetingCoachAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Certification $certification;

    private Enrollment $enrollment;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->certification = Certification::factory()->published()->create();
        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $this->enrollment = Enrollment::factory()->for($this->student, 'user')->for($this->certification)->learning()->create();
    }

    public function test_coach_who_cannot_cover_full_hour_is_not_assigned(): void
    {
        // 割当の同点時は ULID 昇順で選ばれるため、ID が小さい方を「後半 30 分が枠外のコーチ」にする
        [$partialCoach, $fullCoach] = $this->createCoachesOrderedById(2);
        CoachAvailability::factory()->forCoach($partialCoach)->onDay(1)->timeRange('09:30:00', '10:30:00')->create();
        CoachAvailability::factory()->forCoach($fullCoach)->onDay(1)->timeRange('10:00:00', '11:00:00')->create();

        $response = $this->book(10);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'student_id' => $this->student->id,
            'coach_id' => $fullCoach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
        $this->assertDatabaseCount('meetings', 1);
    }

    public function test_coach_whose_availability_ends_exactly_at_meeting_end_is_assigned(): void
    {
        [$coach] = $this->createCoachesOrderedById(1);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('10:00:00', '11:00:00')->create();

        $response = $this->book(10);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'student_id' => $this->student->id,
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
        ]);
    }

    public function test_booking_is_rejected_when_only_partial_coach_exists(): void
    {
        [$coach] = $this->createCoachesOrderedById(1);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:30:00', '10:30:00')->create();

        $response = $this->book(10);

        // 表示枠と割当の判定がずれていないことの確認。09:30〜10:30 の枠では 10:00〜11:00 を満たせないため、
        // 割当前の枠検証(validateSlot)で面談可能時間外として予約させない(修正前も同じ結果になる)
        $response->assertSessionHas('error', '指定された時刻は面談可能時間外です。別の時刻をお選びください。');
        $this->assertDatabaseCount('meetings', 0);
    }

    /**
     * @return list<User>
     */
    private function createCoachesOrderedById(int $count): array
    {
        $coaches = User::factory()->coach()->inProgress()->count($count)->create()
            ->sortBy('id', SORT_STRING)
            ->values()
            ->all();

        foreach ($coaches as $coach) {
            $this->certification->coaches()->attach($coach->id, [
                'id' => (string) Str::ulid(),
                'assigned_by_user_id' => $this->admin->id,
                'assigned_at' => now(),
                'unassigned_at' => null,
            ]);
        }

        return $coaches;
    }

    private function book(int $hour): TestResponse
    {
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime($hour, 0);

        return $this->actingAs($this->student)->post(route('meetings.store', $this->enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '60分間対応できるコーチの確認',
        ]);
    }
}
