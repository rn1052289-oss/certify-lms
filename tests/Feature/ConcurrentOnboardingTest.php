<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\MeetingQuotaTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserStatusLog;
use App\UseCases\Auth\OnboardAction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** B-B-06: 同じ招待URLへの同時送信を独立した2プロセスで確認する。同じDBのテスト群と同時起動しない。 */
class ConcurrentOnboardingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_onboarding_with_same_invitation_succeeds_only_once(): void
    {
        if (! function_exists('pcntl_fork') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('独立プロセスと行ロックの確認には pcntl と MySQL が必要です。');
        }

        $plan = Plan::factory()->published()->create(['duration_days' => 90, 'default_meeting_quota' => 6]);
        $admin = User::factory()->admin()->create();
        $invitedUser = User::factory()->invited()->withPlan($plan)->create();
        $invitation = Invitation::factory()->forUser($invitedUser)->pending()
            ->create(['invited_by_user_id' => $admin->id]);
        $directory = sys_get_temp_dir().'/onboarding-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('検証用プロセスを開始できません。');
                }
                if ($pid === 0) {
                    DB::purge();
                    touch($directory.'/ready-'.$i);
                    $deadline = microtime(true) + 15;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    touch($directory.'/entered-'.$i);
                    try {
                        app(OnboardAction::class)($invitation, [
                            'name' => '同時送信'.$i,
                            'password' => 'secret-pass',
                        ]);
                        $status = 200;
                    } catch (\Throwable $exception) {
                        $status = $exception instanceof HttpExceptionInterface
                            ? $exception->getStatusCode() : 500;
                    }
                    file_put_contents($directory.'/result-'.$i, (string) $status);
                    DB::disconnect();
                    exit(in_array($status, [200, 410], true) ? 0 : 1);
                }
                $children[] = $pid;
            }

            $this->waitForFiles([$directory.'/ready-0', $directory.'/ready-1']);
            DB::beginTransaction();
            Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->waitForFiles([$directory.'/entered-0', $directory.'/entered-1']);
            usleep(300000);
            DB::commit();
            $this->waitForFiles([$directory.'/result-0', $directory.'/result-1']);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            $statuses = [file_get_contents($directory.'/result-0'), file_get_contents($directory.'/result-1')];
            sort($statuses);
            $this->assertSame(['200', '410'], $statuses);
            $this->assertSame(InvitationStatus::Accepted, $invitation->fresh()->status);
            $this->assertSame(UserStatus::InProgress, $invitedUser->fresh()->status);
            $this->assertSame(1, UserStatusLog::where('user_id', $invitedUser->id)->count());
            $this->assertSame(1, MeetingQuotaTransaction::where('user_id', $invitedUser->id)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function waitForFiles(array $files): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (count(array_filter($files, 'file_exists')) === count($files)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('検証用プロセスが制限時間内に応答しませんでした。');
    }
}
