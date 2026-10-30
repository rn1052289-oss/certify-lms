<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Learning;

use App\Enums\AnswerSource;
use App\Enums\CertificationStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\SectionQuestion;
use App\Models\SectionQuestionAnswer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

class ContentVisibilityTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    #[DataProvider('searchAccessCases')]
    public function test_search_respects_certification_and_enrollment_status(
        CertificationStatus $certificationStatus,
        EnrollmentStatus $enrollmentStatus,
        bool $visible,
    ): void {
        [$student, $certification, , $section] = $this->scenario($enrollmentStatus);
        $section->update(['title' => 'Visibility regression', 'body' => 'VISIBILITY_BODY']);
        $certification->update(['status' => $certificationStatus]);

        $response = $this->actingAs($student)->get(route('contents.search', [
            'certification_id' => $certification->id,
            'keyword' => 'VISIBILITY_BODY',
        ]));

        $response->assertOk()
            ->assertViewHas('paginator', fn ($paginator) => $paginator->total() === ($visible ? 1 : 0))
            ->assertViewHas('snippets', fn ($snippets) => count($snippets) === ($visible ? 1 : 0));
        if ($visible) {
            $response->assertSee('Visibility regression');
        } else {
            $response->assertDontSee('Visibility regression');
        }
    }

    public static function searchAccessCases(): array
    {
        return [
            'published learning' => [CertificationStatus::Published, EnrollmentStatus::Learning, true],
            'published passed' => [CertificationStatus::Published, EnrollmentStatus::Passed, true],
            'published failed' => [CertificationStatus::Published, EnrollmentStatus::Failed, false],
            'draft learning' => [CertificationStatus::Draft, EnrollmentStatus::Learning, false],
            'draft passed' => [CertificationStatus::Draft, EnrollmentStatus::Passed, false],
            'archived learning' => [CertificationStatus::Archived, EnrollmentStatus::Learning, false],
            'archived passed' => [CertificationStatus::Archived, EnrollmentStatus::Passed, false],
        ];
    }

    #[DataProvider('hiddenCertificationCases')]
    public function test_unpublished_certification_blocks_quiz_and_drill_pages(
        CertificationStatus $status,
        EnrollmentStatus $enrollmentStatus,
    ): void {
        [$student, $certification, $enrollment, $section, $question] = $this->scenario($enrollmentStatus);
        $answer = SectionQuestionAnswer::factory()->forUser($student)->forQuestion($question)
            ->forOption($question->options->first())->create();
        $certification->update(['status' => $status]);
        $this->actingAs($student);

        foreach (['show', 'question', 'result'] as $page) {
            $this->get(route('quiz.sections.'.$page, [
                'section' => $section, 'question' => $question, 'answer' => $answer,
            ]))->assertNotFound();
        }

        foreach (['index', 'category', 'question', 'result'] as $page) {
            $this->get(route('quiz.drills.'.$page, [
                'enrollment' => $enrollment, 'questionCategory' => $question->category,
                'question' => $question, 'answer' => $answer,
            ]))->assertForbidden();
        }
    }

    #[DataProvider('hiddenCertificationCases')]
    public function test_unpublished_certification_rejects_direct_answers_without_changing_history(
        CertificationStatus $status,
        EnrollmentStatus $enrollmentStatus,
    ): void {
        [$student, $certification, $enrollment, $section, $question] = $this->scenario($enrollmentStatus);
        $certification->update(['status' => $status]);

        foreach ([AnswerSource::SectionQuiz, AnswerSource::WeakDrill] as $source) {
            $this->actingAs($student)->post(route('quiz.answers.store', $question), [
                'selected_option_id' => $question->options->first()->id,
                'source' => $source->value,
                'section_id' => $section->id,
                'enrollment_id' => $enrollment->id,
                'question_category_id' => $question->category_id,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('section_question_answers', 0);
        $this->assertDatabaseCount('section_question_attempts', 0);
    }

    public static function hiddenCertificationCases(): array
    {
        return [
            'draft learning' => [CertificationStatus::Draft, EnrollmentStatus::Learning],
            'draft passed' => [CertificationStatus::Draft, EnrollmentStatus::Passed],
            'archived learning' => [CertificationStatus::Archived, EnrollmentStatus::Learning],
            'archived passed' => [CertificationStatus::Archived, EnrollmentStatus::Passed],
        ];
    }

    #[DataProvider('activeEnrollmentCases')]
    public function test_published_certification_keeps_quiz_and_drill_access(EnrollmentStatus $status): void
    {
        [$student, , $enrollment, $section, $question] = $this->scenario($status);
        $this->actingAs($student)->get(route('quiz.sections.show', $section))->assertOk();
        $this->get(route('quiz.drills.index', $enrollment))->assertOk();

        foreach ([AnswerSource::SectionQuiz, AnswerSource::WeakDrill] as $source) {
            $this->post(route('quiz.answers.store', $question), [
                'selected_option_id' => $question->options->first()->id,
                'source' => $source->value,
                'section_id' => $section->id,
                'enrollment_id' => $enrollment->id,
                'question_category_id' => $question->category_id,
            ])->assertRedirect();
        }

        $this->assertDatabaseCount('section_question_answers', 2);
        $this->assertDatabaseHas('section_question_attempts', [
            'user_id' => $student->id, 'section_question_id' => $question->id, 'attempt_count' => 2,
        ]);
    }

    public static function activeEnrollmentCases(): array
    {
        return [[EnrollmentStatus::Learning], [EnrollmentStatus::Passed]];
    }

    private function scenario(EnrollmentStatus $status): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create(['status' => $status]);
        [, , $section] = $this->makePartChain($certification);
        $question = SectionQuestion::factory()->forSection($section)->published()->withOptions(2)->create();

        return [$student, $certification, $enrollment, $section, $question];
    }
}
