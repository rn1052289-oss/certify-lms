<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_meeting_pack_index(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()->create([
            'name' => 'テスト面談パック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index'));

        $response->assertStatus(200);
        $response->assertSee('テスト面談パック');
    }

    public function test_admin_can_filter_by_keyword(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()->create([
            'name' => '5回面談パック',
        ]);

        MeetingPack::factory()->create([
            'name' => '10回相談プラン',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'keyword' => '面談',
            ]));

        $response->assertStatus(200);
        $response->assertSee('5回面談パック');
        $response->assertDontSee('10回相談プラン');
    }

    public function test_admin_can_filter_by_status(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()->draft()->create([
            'name' => '下書きパック',
        ]);

        MeetingPack::factory()->published()->create([
            'name' => '公開中パック',
        ]);

        MeetingPack::factory()->archived()->create([
            'name' => 'アーカイブパック',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'status' => 'published',
            ]));

        $response->assertStatus(200);
        $response->assertSee('公開中パック');
        $response->assertDontSee('下書きパック');
        $response->assertDontSee('アーカイブパック');
    }

    public function test_index_paginates_meeting_packs(): void
    {
        $admin = User::factory()->admin()->create();

        MeetingPack::factory()
            ->count(21)
            ->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index'));

        $response->assertStatus(200);

        $response->assertViewHas('plans', function ($plans): bool {
            return $plans->total() === 21
                && $plans->perPage() === 20
                && $plans->count() === 20;
        });
    }
}
