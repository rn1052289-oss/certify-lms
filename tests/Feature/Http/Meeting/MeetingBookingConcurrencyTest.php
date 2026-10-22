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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MeetingBookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_requests_book_only_one_meeting_and_consume_only_one_quota(): void
    {
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $scheduledAt = now()->addWeek()->startOfDay()->setTime(10, 0);
        CoachAvailability::factory()->forCoach($coach)->onDay($scheduledAt->dayOfWeek)
            ->timeRange('09:00:00', '18:00:00')->create();
        $students = User::factory()->student()->inProgress()->count(2)->create(['max_meetings' => 3]);
        $barrier = sys_get_temp_dir().'/meeting-booking-'.Str::uuid();
        File::makeDirectory($barrier);
        $processes = [];

        try {
            foreach ($students as $index => $student) {
                $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
                $process = new Process([
                    PHP_BINARY, base_path('tests/Support/book_meeting.php'),
                    $student->id, $enrollment->id, $scheduledAt->format('Y-m-d\TH:i:s'), $barrier, (string) $index,
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

            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }

            sort($statuses);
            $this->assertSame([302, 409], $statuses);
            $this->assertSame(2, count(glob($barrier.'/*')));
            $this->assertSame(1, Meeting::query()->where('coach_id', $coach->id)
                ->where('scheduled_at', $scheduledAt)->where('status', MeetingStatus::Reserved->value)->count());
            $this->assertDatabaseCount('meeting_quota_transactions', 1);
            $this->assertDatabaseHas('meeting_quota_transactions', [
                'type' => MeetingQuotaTransactionType::Consumed->value,
                'amount' => -1,
            ]);
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
