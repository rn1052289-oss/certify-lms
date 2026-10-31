<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Announcement モデルのリレーション・Cast を検証する Unit テスト。
 * 3 リレーション (targetCertification / targetUser / createdBy) + 5 cast を網羅する。
 * targetUser / createdBy は退会済みユーザーも返すことを確認する。
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_certification_relation_returns_target_certification(): void
    {
        $certification = Certification::factory()->create();

        $announcement = $this->createAnnouncement([
            'target_type' => AnnouncementTargetType::Certification,
            'target_certification_id' => $certification->id,
        ]);

        $this->assertTrue($announcement->targetCertification->is($certification));
    }

    public function test_target_user_relation_includes_withdrawn_user(): void
    {
        $student = User::factory()->student()->create();

        $announcement = $this->createAnnouncement([
            'target_type' => AnnouncementTargetType::User,
            'target_user_id' => $student->id,
        ]);
        $student->delete();

        $this->assertTrue($announcement->fresh()->targetUser->is($student));
    }

    public function test_created_by_relation_includes_withdrawn_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $announcement = $this->createAnnouncement(['created_by_user_id' => $admin->id]);
        $admin->delete();

        $this->assertTrue($announcement->fresh()->createdBy->is($admin));
    }

    public function test_relations_return_null_for_all_students_announcement(): void
    {
        $announcement = $this->createAnnouncement();

        $this->assertNull($announcement->targetCertification);
        $this->assertNull($announcement->targetUser);
    }

    public function test_casts_convert_target_type_counts_timestamp_and_failed_user_ids(): void
    {
        $announcement = $this->createAnnouncement([
            'dispatched_count' => '3',
            'dispatched_at' => '2026-10-15 09:00:00',
            'failed_count' => '1',
            'failed_user_ids' => ['01JTESTFAILEDUSER000000000'],
        ])->fresh();

        $this->assertSame(AnnouncementTargetType::AllStudents, $announcement->target_type);
        $this->assertSame(3, $announcement->dispatched_count);
        $this->assertInstanceOf(Carbon::class, $announcement->dispatched_at);
        $this->assertSame(1, $announcement->failed_count);
        $this->assertSame(['01JTESTFAILEDUSER000000000'], $announcement->failed_user_ids);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function createAnnouncement(array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'メンテナンスのお知らせ',
            'body' => '10月20日にメンテナンスを行います。',
            'target_type' => AnnouncementTargetType::AllStudents,
        ], $attributes));
    }
}
