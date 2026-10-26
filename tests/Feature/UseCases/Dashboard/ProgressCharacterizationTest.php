<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\UseCases\Dashboard\FetchStudentDashboardAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 一括進捗集計のクエリ本数を Action の直接呼出しで固定する。
 *
 * @group progress-characterization
 */
class ProgressCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_query_count_stays_one_as_enrollments_increase(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $this->content($student);
        $counts = [];
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if ($this->isProgressAggregate($query)) {
                $count++;
            }
        });
        app(FetchStudentDashboardAction::class)($student);
        $counts[] = $count;
        for ($i = 0; $i < 4; $i++) {
            $this->content($student);
        }
        $count = 0;
        app(FetchStudentDashboardAction::class)($student);
        $counts[] = $count;
        $this->assertSame([1, 1], $counts);
    }

    public function test_no_enrollments_do_not_execute_progress_aggregation(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            if ($this->isProgressAggregate($query)) {
                $count++;
            }
        });
        $this->assertCount(0, app(FetchStudentDashboardAction::class)($student)->enrollmentCards);
        $this->assertSame(0, $count);
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
}
