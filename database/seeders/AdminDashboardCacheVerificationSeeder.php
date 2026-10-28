<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatsService;
use Illuminate\Database\Seeder;

/** 専用の空DBに管理者集計キャッシュ検証用データを用意する。 */
final class AdminDashboardCacheVerificationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || User::query()->exists()) {
            throw new \RuntimeException('local/testing の空DBでのみ実行してください。');
        }

        User::factory()->admin()->inProgress()->create([
            'name' => 'T-A-06 検証管理者',
            'email' => 'admin-ta06@certify-lms.test',
        ]);
        $certification = Certification::factory()->published()->create(['name' => 'T-A-06 集計検証資格']);
        Enrollment::factory()->for($certification)->learning()->count(80)->create();
        Enrollment::factory()->for($certification)->passed()->count(15)->create();
        Enrollment::factory()->for($certification)->failed()->count(5)->create();
        app(EnrollmentStatsService::class)->invalidateAfterCommit();
    }
}
