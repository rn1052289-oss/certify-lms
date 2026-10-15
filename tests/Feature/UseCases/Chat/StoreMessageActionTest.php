<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Chat;

use App\Events\ChatMessageSent;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\ChatMember;
use App\Models\ChatRoom;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\ChatMessageReceivedNotification;
use App\UseCases\Chat\StoreMessageAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * StoreMessageAction の責務:
 *
 * - ChatMessage INSERT + 送信者の ChatMember.last_read_at = now() 更新
 * - DB::afterCommit() で ChatMessageSent broadcast を発火
 * - シグネチャは `__invoke(User, ChatRoom, array)`(E-3 撤回後の単一形態)
 * - 有効なチャット参加者への database / mail 通知
 */
class StoreMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_message_and_update_sender_last_read_at(): void
    {
        Event::fake([ChatMessageSent::class]);

        $sender = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($sender)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();

        $senderMember = ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $sender->id,
            'last_read_at' => null,
        ]);
        ChatMember::factory()->create([
            'chat_room_id' => $room->id,
            'user_id' => $coach->id,
            'last_read_at' => null,
        ]);

        $message = app(StoreMessageAction::class)($sender, $room, ['body' => 'こんにちは']);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'chat_room_id' => $room->id,
            'sender_user_id' => $sender->id,
            'body' => 'こんにちは',
        ]);
        $this->assertNotNull($senderMember->fresh()->last_read_at);

        Event::assertDispatched(ChatMessageSent::class);
    }

    public function test_signature_is_user_chat_room_array(): void
    {
        $reflection = new \ReflectionMethod(StoreMessageAction::class, '__invoke');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame(User::class, $params[0]->getType()?->getName());
        $this->assertSame(ChatRoom::class, $params[1]->getType()?->getName());
        $this->assertSame('array', $params[2]->getType()?->getName());
    }

    public function test_student_message_notifies_assigned_coach_but_not_sender(): void
    {
        Event::fake([ChatMessageSent::class]);
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);

        $action = $this->app->make(StoreMessageAction::class);
        $message = $action($student, $room, ['body' => '学習について質問です。']);

        Notification::assertSentTo($coach, ChatMessageReceivedNotification::class, function ($notification, $channels) use ($coach, $room): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($coach)['url'] === route('chat.show', $room);
        });
        Notification::assertNotSentTo($student, ChatMessageReceivedNotification::class);
        $this->assertSame($student->id, $message->sender_user_id);
    }

    public function test_coach_message_notifies_student_but_not_sender(): void
    {
        Event::fake([ChatMessageSent::class]);
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $coach->id,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $coach->id]);

        $action = $this->app->make(StoreMessageAction::class);
        $message = $action($coach, $room, ['body' => '質問への回答です。']);

        Notification::assertSentTo($student, ChatMessageReceivedNotification::class, function ($notification, $channels) use ($student, $room): bool {
            return $channels === ['database', 'mail']
                && $notification->toDatabase($student)['url'] === route('chat.show', $room);
        });
        Notification::assertNotSentTo($coach, ChatMessageReceivedNotification::class);
        $this->assertSame($coach->id, $message->sender_user_id);
    }

    public function test_excludes_inactive_non_member_and_unassigned_coaches(): void
    {
        Event::fake([ChatMessageSent::class]);
        Notification::fake();

        $student = User::factory()->student()->inProgress()->create();
        $graduatedCoach = User::factory()->coach()->graduated()->create();
        $nonMemberCoach = User::factory()->coach()->inProgress()->create();
        $unassignedCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();

        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $graduatedCoach->id,
        ]);
        CertificationCoachAssignment::factory()->create([
            'certification_id' => $certification->id,
            'user_id' => $nonMemberCoach->id,
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();
        $room = ChatRoom::factory()->for($enrollment)->create();
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $student->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $graduatedCoach->id]);
        ChatMember::factory()->create(['chat_room_id' => $room->id, 'user_id' => $unassignedCoach->id]);

        $action = $this->app->make(StoreMessageAction::class);
        $action($student, $room, ['body' => '通知対象の確認です。']);

        Notification::assertNothingSent();
    }
}
