<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\SectionProgress;
use App\Models\User;
use App\Services\LearningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_summaries_preserve_order_counts_rounding_and_empty_completion(): void
    {
        $enrollment = Enrollment::factory()->for(User::factory()->student()->inProgress())
            ->for(Certification::factory()->published())->create();
        $part = Part::factory()->for($enrollment->certification)->published()->create();
        $complete = Chapter::factory()->for($part)->published()->create(['order' => 30]);
        $partial = Chapter::factory()->for($part)->published()->create(['order' => 20]);
        $empty = Chapter::factory()->for($part)->published()->create(['order' => 10]);
        $hidden = Chapter::factory()->for($part)->draft()->create(['order' => 0]);
        $sections = Section::factory()->for($partial)->published()->count(3)->create();
        foreach ($sections->take(2) as $section) {
            SectionProgress::factory()->forEnrollment($enrollment)->forSection($section)->create();
        }
        SectionProgress::factory()->forEnrollment(Enrollment::factory()->for($enrollment->certification)->create())
            ->forSection($sections[2])->create();
        foreach ([$partial, $empty, $hidden] as $chapter) {
            SectionProgress::factory()->forEnrollment($enrollment)
                ->forSection(Section::factory()->for($chapter)->draft()->create())->create();
        }
        SectionProgress::factory()->forEnrollment($enrollment)
            ->forSection(Section::factory()->for($complete)->published()->create())->create();

        $result = app(LearningProgressService::class)->summarizeChapters($part, $enrollment);

        $this->assertSame([$empty->id, $partial->id, $complete->id], $result['chapters']->modelKeys());
        $this->assertSame([
            $empty->id => ['total' => 0, 'completed' => 0, 'isCompleted' => false, 'percentage' => 0],
            $partial->id => ['total' => 3, 'completed' => 2, 'isCompleted' => false, 'percentage' => 67],
            $complete->id => ['total' => 1, 'completed' => 1, 'isCompleted' => true, 'percentage' => 100],
        ], $result['progressByChapter']);
        $this->assertSame(3, $result['chapters']->find($partial->id)->sections_total_count);
        $this->assertEquals([$partial->id => 2, $complete->id => 1], $result['completedByChapter']);
    }

    public function test_chapter_summaries_keep_zero_progress_without_an_enrollment(): void
    {
        $part = Part::factory()->for(Certification::factory()->published())->published()->create();
        $chapter = Chapter::factory()->for($part)->published()->create();
        Section::factory()->for($chapter)->published()->create();

        $result = app(LearningProgressService::class)->summarizeChapters($part, null);

        $this->assertSame([], $result['completedByChapter']);
        $this->assertSame([
            $chapter->id => ['total' => 1, 'completed' => 0, 'isCompleted' => false, 'percentage' => 0],
        ], $result['progressByChapter']);
    }
}
