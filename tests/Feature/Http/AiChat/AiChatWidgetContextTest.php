<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class AiChatWidgetContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true]);
        Http::preventStrayRequests();
    }

    public function test_widget_can_distinguish_saved_conversation_from_current_default_enrollment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $first = $this->enrollment($student);
        $second = $this->enrollment($student);
        $student->update(['default_enrollment_id' => $first->id]);
        $this->actingAs($student);
        $id = $this->postJson('/ai-chat/conversations', ['source' => 'widget'])
            ->assertCreated()->json('conversation.id');
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget')
            ->assertOk()
            ->assertJsonPath('conversation.enrollment_id', $first->id)
            ->assertJsonPath('widget_context.enrollment_id', $first->id)
            ->assertJsonPath('widget_context.section_id', null);

        $student->update(['default_enrollment_id' => $second->id]);
        $this->actingAs($student->fresh());
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget')
            ->assertOk()
            ->assertJsonPath('conversation.enrollment_id', $first->id)
            ->assertJsonPath('widget_context.enrollment_id', $second->id);
        $nextId = $this->postJson('/ai-chat/conversations', ['source' => 'widget'])
            ->assertCreated()->assertJsonPath('conversation.enrollment_id', $second->id)
            ->json('conversation.id');
        $this->assertNotSame($id, $nextId);
        Http::assertNothingSent();
    }

    public function test_widget_context_requires_owned_published_section_and_valid_identifier(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = $this->enrollment($student);
        $section = $this->section($enrollment);
        $otherStudent = User::factory()->student()->inProgress()->create();
        $other = $this->section($this->enrollment($otherStudent));
        $this->actingAs($student);
        $id = $this->postJson('/ai-chat/conversations', ['section_id' => $section->id])
            ->assertCreated()->json('conversation.id');
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget&section_id='.$section->id)
            ->assertOk()
            ->assertJsonPath('widget_context.enrollment_id', $enrollment->id)
            ->assertJsonPath('widget_context.section_id', $section->id);
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget&section_id=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors('section_id');
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget&section_id='.$other->id)
            ->assertNotFound();
        $this->actingAs($otherStudent);
        $this->getJson('/ai-chat/conversations/'.$id.'?source=widget')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_section_reuse_requires_same_enrollment_as_well_as_same_section(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = $this->enrollment($student);
        $other = $this->enrollment($student);
        $section = $this->section($enrollment);
        // 保存済みの紐付けが異なる会話を、教材 ID だけで再利用しない。
        $old = $student->aiChatConversations()->create([
            'enrollment_id' => $other->id,
            'section_id' => $section->id,
            'title' => '別の資格の相談',
            'last_message_at' => now(),
        ]);
        $this->actingAs($student);
        $id = $this->postJson('/ai-chat/conversations', ['section_id' => $section->id])
            ->assertCreated()->assertJsonPath('conversation.enrollment_id', $enrollment->id)
            ->json('conversation.id');
        $this->assertNotSame($old->id, $id);
        $this->postJson('/ai-chat/conversations', ['section_id' => $section->id])
            ->assertOk()->assertJsonPath('conversation.id', $id);
        $this->assertDatabaseHas('ai_chat_conversations', ['id' => $old->id, 'enrollment_id' => $other->id]);
        Http::assertNothingSent();
    }

    private function enrollment(User $student): Enrollment
    {
        return Enrollment::factory()->create([
            'user_id' => $student->id,
            'certification_id' => Certification::factory()->published()->create()->id,
            'status' => EnrollmentStatus::Learning,
        ]);
    }

    private function section(Enrollment $enrollment): Section
    {
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();

        return Section::factory()->published()->forChapter($chapter)->create();
    }
}
