<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/** 独立した2つのHTTP処理を競合させる。他のtesting DBテスト群とは同時起動しない。 */
class ConcurrentAiChatTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'local-test-only', 'ai-chat.auto_title' => false]);
        Http::preventStrayRequests();
    }

    public function test_parallel_sends_cannot_exceed_twentieth_daily_attempt(): void
    {
        $this->requireIndependentProcesses();
        $student = User::factory()->student()->inProgress()->create();
        // 同じ会話のPendingガードではなく、本人全体の日次上限を検証する。
        $conversations = [];
        for ($i = 0; $i < 2; $i++) {
            $conversations[] = $student->aiChatConversations()->create(['title' => '上限確認'.$i, 'last_message_at' => now()]);
        }
        DB::table('ai_chat_daily_usages')->insert(['user_id' => $student->id, 'usage_date' => now()->toDateString(), 'attempts' => 19]);
        $results = $this->parallelRequests($student, [
            ['/ai-chat/conversations/'.$conversations[0]->id.'/messages', ['content' => '20回目を争う質問0']],
            ['/ai-chat/conversations/'.$conversations[1]->id.'/messages', ['content' => '20回目を争う質問1']],
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 429], $statuses);
        $this->assertSame(20, (int) DB::table('ai_chat_daily_usages')->where('user_id', $student->id)->where('usage_date', now()->toDateString())->value('attempts'));
        $this->assertSame(1, DB::table('ai_chat_messages')->whereIn('ai_chat_conversation_id', array_map(fn (AiChatConversation $conversation) => $conversation->id, $conversations))->where('role', 'user')->count());
        $this->assertSame(1, DB::table('ai_chat_messages')->whereIn('ai_chat_conversation_id', array_map(fn (AiChatConversation $conversation) => $conversation->id, $conversations))->where('role', 'assistant')->where('status', 'completed')->count());
        $this->assertSame(1, array_sum(array_column($results, 'api_calls')));
    }

    public function test_parallel_starts_reuse_the_same_section_conversation(): void
    {
        $this->requireIndependentProcesses();
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $part = Part::factory()->published()->forCertification($enrollment->certification)->create();
        $chapter = Chapter::factory()->published()->forPart($part)->create();
        $section = Section::factory()->published()->forChapter($chapter)->create();
        $request = ['/ai-chat/conversations', ['source' => 'widget', 'section_id' => $section->id]];
        $results = $this->parallelRequests($student, [$request, $request]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $firstId = $results[0]['body']['conversation']['id'] ?? null;
        $this->assertNotNull($firstId);
        $this->assertSame($firstId, $results[1]['body']['conversation']['id'] ?? null);
        $this->assertSame(1, AiChatConversation::where('user_id', $student->id)->where('section_id', $section->id)->count());
        $this->assertSame(0, array_sum(array_column($results, 'api_calls')));
        $this->assertDatabaseCount('ai_chat_messages', 0);
    }

    private function requireIndependentProcesses(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('独立HTTPと行ロックの確認にはpcntl・posix・MySQLが必要です。');
        }
    }

    /** @return array<int, array{status: int, body: ?array, api_calls: int}> */
    private function parallelRequests(User $student, array $requests): array
    {
        $directory = sys_get_temp_dir().'/ai-chat-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach ($requests as $i => [$url, $data]) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('独立HTTPプロセスを開始できません。');
                }
                if ($pid === 0) {
                    $exit = 0;
                    try {
                        DB::purge();
                        Auth::guard('web')->setUser(User::findOrFail($student->id));
                        $apiCalls = 0;
                        Http::fake(function () use (&$apiCalls) {
                            $apiCalls++;

                            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '検証用の回答']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]]);
                        });
                        touch($directory.'/ready-'.$i);
                        $this->waitForFiles([$directory.'/start']);
                        $request = Request::create($url, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($data));
                        touch($directory.'/entered-'.$i);
                        $kernel = app(Kernel::class);
                        $response = $kernel->handle($request);
                        file_put_contents($directory.'/result-'.$i, json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'api_calls' => $apiCalls]));
                        $kernel->terminate($request, $response);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$i, json_encode(['status' => 0, 'body' => ['exception' => $exception::class, 'message' => $exception->getMessage()], 'api_calls' => 0]));
                        $exit = 1;
                    }
                    DB::disconnect();
                    exit($exit);
                }
                $children[] = $pid;
            }
            $this->waitForFiles([$directory.'/ready-0', $directory.'/ready-1']);
            DB::beginTransaction();
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->waitForFiles([$directory.'/entered-0', $directory.'/entered-1']);
            usleep(300000);
            DB::commit();
            $this->waitForFiles([$directory.'/result-0', $directory.'/result-1']);
            $results = [];
            foreach ($children as $i => $pid) {
                pcntl_waitpid($pid, $status);
                $result = json_decode(file_get_contents($directory.'/result-'.$i), true);
                $this->assertSame(0, pcntl_wexitstatus($status), json_encode($result, JSON_UNESCAPED_UNICODE));
                $results[] = $result;
            }
            $children = [];

            return $results;
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
            clearstatcache();
            if (count(array_filter($files, 'file_exists')) === count($files)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('並行HTTP検証が制限時間内に完了しませんでした。');
    }
}
