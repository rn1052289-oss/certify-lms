<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\Learning\ProgressSummary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Service 分離前の画面データを固定する特性テスト。
 *
 * @group progress-characterization
 */
class ProgressCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_levels_and_rounding_are_identical_for_admin_coach_and_learning_top(): void
    {
        $enrollment = $this->enrollment();
        $parts = Part::factory()->for($enrollment->certification)->published()->count(3)->create();
        foreach ($parts as $index => $part) {
            $chapter = Chapter::factory()->for($part)->published()->create();
            $section = Section::factory()->for($chapter)->published()->create();
            if ($index === 0) {
                $this->complete($enrollment, $section);
            }
        }

        $this->assertScreens($enrollment, new ProgressSummary(3, 1, 0.3333, 3, 1, 0.3333, 3, 1, 0.3333, 0.3333));
    }

    public function test_partial_chapters_and_parts_have_independent_completion_ratios(): void
    {
        $enrollment = $this->enrollment();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $completeChapter = Chapter::factory()->for($part)->published()->create();
        $partialChapter = Chapter::factory()->for($part)->published()->create();
        $unreadPart = Part::factory()->for($enrollment->certification)->published()->create();
        $unreadChapter = Chapter::factory()->for($unreadPart)->published()->create();
        $completedSection = Section::factory()->for($completeChapter)->published()->create();
        $partialSections = Section::factory()->for($partialChapter)->published()->count(2)->create();
        Section::factory()->for($unreadChapter)->published()->count(2)->create();
        $this->complete($enrollment, $completedSection);
        $this->complete($enrollment, $partialSections[0]);

        $this->assertScreens($enrollment, new ProgressSummary(
            sectionsTotal: 5,
            sectionsCompleted: 2,
            sectionCompletionRatio: 0.4,
            chaptersTotal: 3,
            chaptersCompleted: 1,
            chapterCompletionRatio: 0.3333,
            partsTotal: 2,
            partsCompleted: 0,
            partCompletionRatio: 0.0,
            overallCompletionRatio: 0.4,
        ));
    }

    public function test_certification_without_content_has_zero_totals_and_float_ratios(): void
    {
        $this->assertScreens($this->enrollment(), new ProgressSummary(0, 0, 0.0, 0, 0, 0.0, 0, 0, 0.0, 0.0));
    }

    /** @dataProvider unpublishedLevels */
    public function test_unpublished_content_is_excluded_from_both_counts(string $level): void
    {
        $enrollment = $this->enrollment();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $visible = Section::factory()->for($chapter)->published()->create();
        $this->complete($enrollment, $visible);

        $hiddenPart = $level === 'part' ? Part::factory()->for($enrollment->certification)->draft()->create() : $part;
        $hiddenChapter = $level !== 'section' ? Chapter::factory()->for($hiddenPart)->published()->create() : $chapter;
        if ($level === 'chapter') {
            $hiddenChapter->update(['status' => ContentStatus::Draft]);
        }
        $hiddenSection = Section::factory()->for($hiddenChapter)->published()->create();
        if ($level === 'section') {
            $hiddenSection->update(['status' => ContentStatus::Draft]);
        }
        $this->complete($enrollment, $hiddenSection);
        // 非公開の未読も加え、分母だけ・分子だけの条件漏れを区別する。
        Section::factory()->for($hiddenChapter)->create(['status' => $level === 'section' ? ContentStatus::Draft : ContentStatus::Published]);

        $this->assertScreens($enrollment, new ProgressSummary(1, 1, 1.0, 1, 1, 1.0, 1, 1, 1.0, 1.0));
    }

    public static function unpublishedLevels(): array
    {
        return [['part'], ['chapter'], ['section']];
    }

    public function test_empty_published_chapters_and_parts_are_not_complete(): void
    {
        $enrollment = $this->enrollment();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        Chapter::factory()->for($part)->published()->create();
        $draftOnly = Chapter::factory()->for($part)->published()->create();
        $this->complete($enrollment, Section::factory()->for($draftOnly)->draft()->create());
        Part::factory()->for($enrollment->certification)->published()->create();

        $this->assertScreens($enrollment, new ProgressSummary(0, 0, 0.0, 2, 0, 0.0, 2, 0, 0.0, 0.0));
    }

    public function test_other_enrollment_progress_is_not_counted(): void
    {
        $enrollment = $this->enrollment();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $section = Section::factory()->for($chapter)->published()->create();
        $other = Enrollment::factory()->for($enrollment->certification)->create();
        $this->complete($other, $section);

        $this->assertScreens($enrollment, new ProgressSummary(1, 0, 0.0, 1, 0, 0.0, 1, 0, 0.0, 0.0));
    }

    public function test_student_enrollment_detail_keeps_null_progress_without_aggregate_queries(): void
    {
        $enrollment = $this->enrollment();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'count(') && str_contains($query->sql, 'section_progresses')) {
                $queries[] = $query->sql;
            }
        });

        $this->actingAs($enrollment->user)->get(route('enrollments.show', $enrollment))
            ->assertOk()->assertViewHas('progress', fn ($progress) => $progress === null);
        $this->assertSame([], $queries);
    }

    public function test_part_page_counts_completed_published_sections_per_visible_chapter(): void
    {
        $enrollment = $this->enrollment();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        $empty = Chapter::factory()->for($part)->published()->create();
        $hidden = Chapter::factory()->for($part)->draft()->create();
        $sections = Section::factory()->for($chapter)->published()->count(3)->create();
        $this->complete($enrollment, $sections[0]);
        $this->complete($enrollment, $sections[1]);
        $this->complete($enrollment, Section::factory()->for($chapter)->draft()->create());
        $this->complete($enrollment, Section::factory()->for($hidden)->published()->create());
        $other = Enrollment::factory()->for($enrollment->certification)->create();
        $this->complete($other, $sections[2]);

        $response = $this->actingAs($enrollment->user)->get(route('learning.parts.show', $part))->assertOk();
        $response->assertViewHas('completedByChapter', [$chapter->id => 2]);
        $chapters = $response->viewData('chapters')->keyBy('id');
        $this->assertSame(3, $chapters[$chapter->id]->sections_total_count);
        $this->assertSame(0, $chapters[$empty->id]->sections_total_count);
        $this->assertFalse($chapters->has($hidden->id));
    }

    private function enrollment(): Enrollment
    {
        return Enrollment::factory()->for(User::factory()->student()->inProgress())
            ->for(Certification::factory()->published())->create();
    }

    private function complete(Enrollment $enrollment, Section $section): void
    {
        SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
    }

    private function assertScreens(Enrollment $enrollment, ProgressSummary $expected): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        $enrollment->certification->coaches()->attach($coach, ['assigned_by_user_id' => $admin->id, 'assigned_at' => now()]);
        foreach ([$admin, $coach, $enrollment->user] as $user) {
            $route = $user->id === $enrollment->user_id ? 'learning.enrollments.show' : 'enrollments.show';
            $response = $this->actingAs($user)->get(route($route, $enrollment))->assertOk();
            $actual = $response->viewData('progress');
            $this->assertSame((array) $expected, (array) $actual);
            $this->assertSame($actual->sectionCompletionRatio, $actual->overallCompletionRatio);
        }
    }
}
