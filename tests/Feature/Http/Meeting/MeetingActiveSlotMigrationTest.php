<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MeetingActiveSlotMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_10_17_000001_add_active_slot_unique_to_meetings_table.php');
    }

    public function test_migration_stops_without_changing_meetings_when_active_duplicates_exist(): void
    {
        try {
            $this->migration->down();
            $reserved = Meeting::factory()->reserved()->create();
            $completed = Meeting::factory()->completed()->forCoach($reserved->coach)->create([
                'scheduled_at' => $reserved->scheduled_at,
            ]);

            try {
                $this->migration->up();
                $this->fail('有効な予約が重複しているのにMigrationが完了しました。');
            } catch (RuntimeException $e) {
                $this->assertSame('有効な面談予約が重複しています。予約内容を確認してから再実行してください。', $e->getMessage());
            }

            $this->assertFalse(Schema::hasColumn('meetings', 'active_slot'));
            $this->assertDatabaseHas('meetings', ['id' => $reserved->id, 'status' => MeetingStatus::Reserved->value]);
            $this->assertDatabaseHas('meetings', ['id' => $completed->id, 'status' => MeetingStatus::Completed->value]);
        } finally {
            $this->restoreActiveSlotSchema();
        }
    }

    public function test_canceled_duplicates_do_not_stop_the_migration(): void
    {
        try {
            $this->migration->down();
            $reserved = Meeting::factory()->reserved()->create();
            Meeting::factory()->canceled()->count(2)->forCoach($reserved->coach)->create([
                'scheduled_at' => $reserved->scheduled_at,
            ]);

            $this->migration->up();

            $this->assertTrue(Schema::hasColumn('meetings', 'active_slot'));
            $this->assertDatabaseCount('meetings', 3);
        } finally {
            $this->restoreActiveSlotSchema();
        }
    }

    public function test_rollback_removes_the_constraint_and_migrating_again_restores_it(): void
    {
        try {
            $this->migration->down();

            $this->assertFalse(Schema::hasColumn('meetings', 'active_slot'));
            $meeting = Meeting::factory()->reserved()->create();
            $duplicate = Meeting::factory()->reserved()->forCoach($meeting->coach)->create([
                'scheduled_at' => $meeting->scheduled_at,
            ]);
            $duplicate->delete();

            $this->migration->up();

            $this->assertTrue(Schema::hasColumn('meetings', 'active_slot'));
            $this->expectException(UniqueConstraintViolationException::class);
            Meeting::factory()->reserved()->forCoach($meeting->coach)->create([
                'scheduled_at' => $meeting->scheduled_at,
            ]);
        } finally {
            $this->restoreActiveSlotSchema();
        }
    }

    /**
     * 途中で失敗しても、テスト終了時の migrate:rollback が適用済みの状態から実行できるようにする。
     */
    private function restoreActiveSlotSchema(): void
    {
        if (! Schema::hasColumn('meetings', 'active_slot')) {
            DB::table('meetings')->delete();
            $this->migration->up();
        }
    }
}
