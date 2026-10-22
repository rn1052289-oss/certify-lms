<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ExternalApi\StripeSignature;
use Tests\TestCase;

/**
 * HTTP 処理を独立した 2 プロセスで実行する。通常テスト群との同時起動は禁止。
 */
#[Group('external-api')]
class ConcurrentStripeWebhookTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_paid_notifications_both_succeed_and_grant_only_once(): void
    {
        if (! function_exists('pcntl_fork') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('独立プロセスと行ロックの確認には pcntl と MySQL が必要です。');
        }

        config(['services.stripe.webhook_secret' => 'whsec_concurrency_local_test']);
        $payment = Payment::factory()->create();
        $payload = json_encode([
            'id' => 'evt_concurrent', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $payment->stripe_checkout_session_id, 'object' => 'checkout.session',
                'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
                'payment_intent' => 'pi_test_'.$payment->id,
                'client_reference_id' => $payment->id, 'amount_total' => $payment->amount, 'currency' => 'jpy',
                'metadata' => ['payment_id' => $payment->id, 'user_id' => $payment->user_id],
            ]],
        ]);
        $directory = sys_get_temp_dir().'/stripe-webhook-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        // fork 前に親の接続を閉じ、子同士で MySQL 接続を共有しない。
        DB::disconnect();

        try {
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('検証用 HTTP プロセスを開始できません。');
                }
                if ($pid === 0) {
                    DB::purge();
                    touch($directory.'/ready-'.$i);
                    $deadline = microtime(true) + 15;
                    while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    $signature = StripeSignature::make($payload, 'whsec_concurrency_local_test');
                    $request = Request::create('/webhooks/stripe', 'POST', [], [], [], [
                        'HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    ], $payload);
                    touch($directory.'/entered-'.$i);
                    $kernel = app(Kernel::class);
                    $response = $kernel->handle($request);
                    file_put_contents($directory.'/result-'.$i, (string) $response->getStatusCode());
                    $kernel->terminate($request, $response);
                    DB::disconnect();
                    exit($response->getStatusCode() === 200 ? 0 : 1);
                }
                $children[] = $pid;
            }

            $this->waitForFiles([$directory.'/ready-0', $directory.'/ready-1']);
            DB::beginTransaction();
            Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->waitForFiles([$directory.'/entered-0', $directory.'/entered-1']);
            // 両 HTTP 処理が同じ決済行の更新待ちになる時間を設ける。
            usleep(300000);
            DB::commit();
            $this->waitForFiles([$directory.'/result-0', $directory.'/result-1']);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];

            $this->assertSame('200', file_get_contents($directory.'/result-0'));
            $this->assertSame('200', file_get_contents($directory.'/result-1'));
            $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
            $this->assertSame(1, MeetingQuotaTransaction::where('related_payment_id', $payment->id)->count());
            $this->assertSame(3, (int) MeetingQuotaTransaction::where('related_payment_id', $payment->id)->sum('amount'));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') as $file) {
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

        $this->fail('並行 HTTP 検証が制限時間内に完了しませんでした。');
    }
}
