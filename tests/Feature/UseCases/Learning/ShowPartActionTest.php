<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Learning;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Part;
use App\Models\User;
use App\UseCases\Learning\ShowPartAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 未登録の受講生に対する Part 表示データのフォールバックを固定する。
 *
 * @group progress-characterization
 */
class ShowPartActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unenrolled_student_has_no_completed_sections(): void
    {
        $part = Part::factory()->for(Certification::factory()->published())->published()->create();
        Chapter::factory()->for($part)->published()->create();
        $student = User::factory()->student()->create();

        $data = app(ShowPartAction::class)($part, $student);

        $this->assertSame([], $data['completedByChapter']);
        $this->assertCount(1, $data['chapters']);
    }
}
