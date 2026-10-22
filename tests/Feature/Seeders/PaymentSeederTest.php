<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Database\Seeders\PaymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class PaymentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_and_demo_students_get_all_states_and_only_succeeded_adds_quota_once(): void
    {
        $fixed = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        $demo = User::factory()->student()->create();
        $coach = User::factory()->coach()->create();
        $zero = User::factory()->student()->create(['email' => 'student-noquota@certify-lms.test']);
        $pack = MeetingPack::factory()->published()->withCount(5)->create();

        $this->seed(PaymentSeeder::class);
        $this->seed(PaymentSeeder::class);

        $this->assertDatabaseCount('payments', 6);
        $this->assertDatabaseCount('meeting_quota_transactions', 2);
        foreach ([$fixed, $demo] as $user) {
            $this->assertSame(5, app(MeetingQuotaService::class)->remaining($user));
            foreach (PaymentStatus::cases() as $status) {
                if ($status === PaymentStatus::Refunded) {
                    continue;
                }
                $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'status' => $status->value, 'meeting_pack_id' => $pack->id]);
            }
        }
        $this->assertSame(0, Payment::where('user_id', $coach->id)->count());
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($zero));
        $this->assertSame(0, Payment::where('user_id', $zero->id)->count());
    }
}
