<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MeetingPackPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_detail_and_list_show_the_existing_payment_contract(): void
    {
        $payment = Payment::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.meeting-packs.show', $payment->meeting_pack_id))
            ->assertOk()->assertSee($payment->user->name)->assertSee('保留');
        $this->get(route('admin.meeting-packs.index'))->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->firstWhere('id', $payment->meeting_pack_id)->payments_count === 1);
    }

    public function test_archived_pack_with_purchase_history_cannot_be_deleted_but_unused_pack_can(): void
    {
        $payment = Payment::factory()->create();
        $payment->meetingPack->update(['status' => MeetingPackStatus::Archived]);
        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('admin.meeting-packs.destroy', $payment->meeting_pack_id))->assertStatus(409);
        $this->assertDatabaseHas('meeting_packs', ['id' => $payment->meeting_pack_id]);
        $this->delete(route('admin.meeting-packs.destroy', $payment->meeting_pack_id))
            ->assertRedirect()->assertSessionHas('error', '購入履歴のある面談パックは削除できません。');
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $unused = MeetingPack::factory()->archived()->create();
        $this->delete(route('admin.meeting-packs.destroy', $unused))->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $unused->id]);
    }

    public function test_action_rechecks_pack_status_after_validation(): void
    {
        config(['services.stripe.secret' => 'sk_test_local_only']);
        $student = User::factory()->student()->create();
        $pack = MeetingPack::factory()->archived()->create();
        try {
            app(CreateCheckoutAction::class)($student, $pack->id);
            $this->fail('非公開のパックが購入できました。');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
    }
}
