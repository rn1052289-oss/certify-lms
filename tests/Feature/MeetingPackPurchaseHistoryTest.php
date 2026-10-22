<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\UseCases\MeetingQuota\CreateCheckoutAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[Group('external-api')]
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

    public function test_admin_detail_shows_only_latest_twenty_payments_without_limiting_list_count(): void
    {
        $pack = MeetingPack::factory()->published()->create();
        $student = User::factory()->student()->create(['name' => '購入履歴の受講生']);
        $paymentIds = [];
        $createdAt = now();
        for ($i = 0; $i < 21; $i++) {
            $paymentIds[] = Payment::factory()->create([
                'meeting_pack_id' => $pack->id,
                'user_id' => $student->id,
                // 最新2件は同時刻にし、同時刻でも順序が安定することを確認する。
                'created_at' => $createdAt->copy()->subMinutes(max(21 - $i, 2)),
            ])->id;
        }
        $expectedIds = array_slice(array_reverse($paymentIds), 0, 20);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.meeting-packs.show', $pack))
            ->assertOk()->assertSee($student->name)
            ->assertViewHas('plan', function (MeetingPack $plan) use ($expectedIds) {
                $this->assertSame($expectedIds, $plan->payments->modelKeys());
                foreach ($plan->payments as $payment) {
                    $this->assertTrue($payment->relationLoaded('user'));
                }

                return true;
            });
        $this->get(route('admin.meeting-packs.index'))->assertOk()
            ->assertViewHas('plans', fn ($plans) => $plans->firstWhere('id', $pack->id)->payments_count === 21);
        $this->assertDatabaseCount('payments', 21);
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
