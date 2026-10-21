<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MeetingQuotaConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_student_parallel_requests_cannot_exceed_remaining_quota(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $day = now()->addWeek()->startOfDay();
        CoachAvailability::factory()->forCoach($coach)->onDay($day->dayOfWeek)
            ->timeRange('09:00:00', '18:00:00')->create();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 1]);
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        $barrier = sys_get_temp_dir().'/meeting-quota-'.Str::uuid();
        File::makeDirectory($barrier);
        $processes = [];

        try {
            foreach ([10, 11] as $index => $hour) {
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/book_meeting_same_student.php'),
                    $student->id, $enrollment->id, $day->copy()->setTime($hour, 0)->format('Y-m-d\TH:i:s'), $barrier, (string) $index,
                ], base_path(), [
                    'APP_ENV' => 'testing',
                    'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'MAIL_MAILER' => 'array',
                    'SESSION_DRIVER' => 'array',
                    'CACHE_DRIVER' => 'array',
                ]);
                $process->setTimeout(20);
                $processes[] = $process;
            }

            foreach ($processes as $process) {
                $process->start();
            }

            $responses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = array_column($responses, 'status');
            sort($statuses);
            $this->assertSame([302, 409], $statuses);
            $rejected = collect($responses)->firstWhere('status', 409);
            $this->assertStringContainsString(
                '面談回数が不足しています',
                json_decode($rejected['body'], true, flags: JSON_THROW_ON_ERROR)['message'],
            );
            $this->assertSame(1, Meeting::query()->where('student_id', $student->id)
                ->where('status', MeetingStatus::Reserved->value)->count());
            $this->assertDatabaseCount('meetings', 1);
            $this->assertDatabaseCount('meeting_quota_transactions', 1);
            $this->assertDatabaseHas('meeting_quota_transactions', [
                'user_id' => $student->id,
                'type' => MeetingQuotaTransactionType::Consumed->value,
                'amount' => -1,
            ]);
            $this->assertSame(0, app(MeetingQuotaService::class)->remaining($student->fresh()));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            File::deleteDirectory($barrier);
        }
    }
}
