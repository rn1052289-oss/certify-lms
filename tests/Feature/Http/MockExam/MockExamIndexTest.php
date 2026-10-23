<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MockExamIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_displays_actual_question_counts_and_preserves_other_row_values(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create(['name' => '検証資格']);
        $creator = User::factory()->admin()->create(['name' => '作成者']);
        $updater = User::factory()->admin()->create(['name' => '更新者']);
        $deletedUpdater = User::factory()->admin()->create();
        $deletedUpdater->delete();
        $exams = [];

        foreach ([0, 1, 3] as $count) {
            $exam = MockExam::factory()->forCertification($certification)->create([
                'title' => '問題数'.$count,
                'order' => $count,
                'passing_score' => 60 + $count,
                'is_published' => $count > 0,
                'created_by_user_id' => $creator->id,
                'updated_by_user_id' => $count === 0 ? $deletedUpdater->id : $updater->id,
            ]);
            MockExamQuestion::factory()->count($count)->forMockExam($exam)->create();
            $exams[] = $exam;
        }

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        $items = $response->viewData('mockExams')->items();
        $this->assertSame(array_column($exams, 'id'), array_column($items, 'id'));
        foreach ($items as $index => $exam) {
            $count = [0, 1, 3][$index];
            $this->assertSame($count, $exam->mock_exam_questions_count);
            $this->assertTrue($exam->relationLoaded('certification'));
            $this->assertTrue($exam->relationLoaded('createdBy'));
            $this->assertTrue($exam->relationLoaded('updatedBy'));
            $this->assertSame($creator->id, $exam->createdBy->id);
            $this->assertFalse($exam->relationLoaded('mockExamQuestions'));
        }
        $this->assertSame([
            ['問題数0', '検証資格', '0', '60%', '下書き', '—', '詳細'],
            ['問題数1', '検証資格', '1', '61%', '公開中', '更新者', '詳細'],
            ['問題数3', '検証資格', '3', '63%', '公開中', '更新者', '詳細'],
        ], $this->tableRows($response->getContent()));
    }

    public function test_index_preserves_certification_order_and_updated_at_order_with_deleted_users(): void
    {
        $admin = User::factory()->admin()->create();
        $certifications = Certification::factory()->count(2)->published()->create()->sortBy('id')->values();
        $deleted = User::factory()->admin()->create();
        $deleted->delete();
        $laterCertification = MockExam::factory()->forCertification($certifications[1])->create(['order' => 0]);
        $laterOrder = MockExam::factory()->forCertification($certifications[0])->create(['order' => 2]);
        $older = MockExam::factory()->forCertification($certifications[0])->create(['order' => 1, 'updated_at' => now()->subDay()]);
        $newer = MockExam::factory()->forCertification($certifications[0])->create([
            'order' => 1, 'updated_at' => now(),
            'created_by_user_id' => $deleted->id, 'updated_by_user_id' => $deleted->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
        $items = $response->viewData('mockExams')->items();
        $this->assertSame([$newer->id, $older->id, $laterOrder->id, $laterCertification->id], array_column($items, 'id'));
        $this->assertNull($items[0]->createdBy);
        $this->assertNull($items[0]->updatedBy);
        $this->assertSame('—', $this->tableRows($response->getContent())[0][5]);
    }

    public function test_index_preserves_filters_twenty_item_pagination_and_query_string(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        $ids = [];
        for ($i = 0; $i < 24; $i++) {
            $ids[] = MockExam::factory()->published()->forCertification($certification)->create(['title' => '対象模試'.$i, 'order' => $i])->id;
        }
        $draft = MockExam::factory()->forCertification($certification)->create(['title' => '対象下書き']);
        MockExam::factory()->published()->forCertification($certification)->create(['title' => '別キーワード']);
        MockExam::factory()->published()->create(['title' => '対象別資格']);
        $filters = ['keyword' => '対象', 'certification_id' => $certification->id, 'is_published' => '1'];

        foreach ([1, 2, 3] as $page) {
            $response = $this->actingAs($admin)->get(route('admin.mock-exams.index', $filters + ['page' => $page]))->assertOk();
            $paginator = $response->viewData('mockExams');
            $this->assertSame(24, $paginator->total());
            $this->assertSame(20, $paginator->perPage());
            $this->assertSame($page, $paginator->currentPage());
            $this->assertSame(array_slice($ids, ($page - 1) * 20, 20), array_column($paginator->items(), 'id'));
            parse_str(parse_url($paginator->url(2), PHP_URL_QUERY), $query);
            $this->assertSame($filters + ['page' => '2'], $query);
        }
        foreach (['0', 'false'] as $value) {
            $response = $this->get(route('admin.mock-exams.index', array_replace($filters, ['is_published' => $value])))->assertOk();
            $this->assertSame([$draft->id], array_column($response->viewData('mockExams')->items(), 'id'));
        }
        $response = $this->get(route('admin.mock-exams.index', array_replace($filters, ['is_published' => 'true'])))->assertOk();
        $this->assertSame(24, $response->viewData('mockExams')->total());
    }

    public function test_coach_only_sees_assigned_certifications_even_when_filtering_other_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();
        $assigned = Certification::factory()->count(2)->published()->create()->sortBy('id')->values();
        foreach ($assigned as $certification) {
            $certification->coaches()->attach($coach->id, ['assigned_by_user_id' => $admin->id, 'assigned_at' => now()]);
        }
        $own = $assigned->map(fn ($certification) => MockExam::factory()->forCertification($certification)->create());
        $other = MockExam::factory()->create();
        $response = $this->actingAs($coach)->get(route('admin.mock-exams.index'))->assertOk();
        $this->assertSame($own->pluck('id')->all(), array_column($response->viewData('mockExams')->items(), 'id'));
        $response = $this->get(route('admin.mock-exams.index', ['certification_id' => $assigned[0]->id]))->assertOk();
        $this->assertSame([$own[0]->id], array_column($response->viewData('mockExams')->items(), 'id'));
        $response = $this->get(route('admin.mock-exams.index', ['certification_id' => $other->certification_id]))->assertOk();
        $this->assertSame(0, $response->viewData('mockExams')->total());
        $response->assertSee('模試マスタがまだありません')->assertDontSee($other->title);
    }

    public function test_empty_index_and_unauthenticated_access_are_unchanged(): void
    {
        $this->get(route('admin.mock-exams.index'))->assertRedirect(route('login'));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk()
            ->assertSee('模試マスタがまだありません')->assertViewHas('mockExams', fn ($paginator) => $paginator->total() === 0);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function tableRows(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//table/tbody/tr') as $row) {
            $cells = [];
            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent));
            }
            $rows[] = $cells;
        }

        return $rows;
    }
}
