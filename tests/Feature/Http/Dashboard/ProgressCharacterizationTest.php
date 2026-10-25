<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ダッシュボードの進捗表示と例外時のフォールバックを HTTP 経由で固定する。
 *
 * @group progress-characterization
 */
class ProgressCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_ratios_match_enrollment_and_certification_on_dashboard_cards(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        [$first, $sections] = $this->content($student);
        $this->complete($first, $sections[0]);
        [$second, $otherSections] = $this->content($student);
        foreach ($otherSections as $section) {
            $this->complete($second, $section);
        }
        $unreadStudent = User::factory()->student()->inProgress()->create();
        $unread = Enrollment::factory()->for($unreadStudent)->for($first->certification)->create();
        $empty = Enrollment::factory()->for($student)->create();
        // 各階層の非公開について、読了と未読を追加して分子・分母の除外を確認。
        foreach (['part', 'chapter', 'section'] as $level) {
            $part = Part::factory()->for($first->certification)->published()->create();
            $chapter = Chapter::factory()->for($part)->published()->create();
            $hidden = Section::factory()->for($chapter)->published()->count(2)->create();
            if ($level === 'part') {
                $part->update(['status' => ContentStatus::Draft]);
            } elseif ($level === 'chapter') {
                $chapter->update(['status' => ContentStatus::Draft]);
            } else {
                foreach ($hidden as $section) {
                    $section->update(['status' => ContentStatus::Draft]);
                }
            }
            $this->complete($first, $hidden[0]);
        }

        $response = $this->actingAs($student)->get(route('dashboard.index'))->assertOk();
        $cards = $response->viewData('viewModel')->enrollmentCards->keyBy('enrollmentId');
        $this->assertSame(0.3333, $cards[$first->id]->progressRatio);
        $this->assertSame(1.0, $cards[$second->id]->progressRatio);
        $this->assertSame(0.0, $cards[$empty->id]->progressRatio);
        $this->assertFalse($cards->has($unread->id));
        $unreadResponse = $this->actingAs($unreadStudent)->get(route('dashboard.index'))->assertOk();
        $this->assertSame(0.0, $unreadResponse->viewData('viewModel')->enrollmentCards->sole()->progressRatio);
    }

    public function test_aggregate_failure_keeps_cards_with_null_progress(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->content($student);
        DB::listen(function (QueryExecuted $query): void {
            if ($this->isProgressAggregate($query)) {
                throw new \RuntimeException('Progress aggregation unavailable');
            }
        });
        $response = $this->actingAs($student)->get(route('dashboard.index'))->assertOk();
        $this->assertNull($response->viewData('viewModel')->enrollmentCards->sole()->progressRatio);
    }

    private function isProgressAggregate(QueryExecuted $query): bool
    {
        return str_contains(strtolower($query->sql), 'count(')
            && str_contains($query->sql, 'section_progresses');
    }

    private function content(User $student): array
    {
        $enrollment = Enrollment::factory()->for($student)->for(Certification::factory()->published())->create();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();

        return [$enrollment, Section::factory()->for($chapter)->published()->count(3)->create()];
    }

    private function complete(Enrollment $enrollment, Section $section): void
    {
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
    }
}
