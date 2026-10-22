<?php

declare(strict_types=1);

namespace Tests\Unit\ExternalApi;

use App\Exceptions\GeminiException;
use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external-api')]
class GeminiClientTest extends TestCase
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai-chat.gemini.api_key' => 'test-api-key', 'ai-chat.gemini.model' => 'test-model']);
    }

    public function test_prompt_structure_and_public_answer_parts_are_preserved(): void
    {
        $contents = [
            ['role' => 'user', 'parts' => [['text' => '前の質問']]],
            ['role' => 'model', 'parts' => [['text' => '前の回答']]],
            ['role' => 'user', 'parts' => [['text' => '今回の質問']]],
        ];
        Http::fake([self::URL => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['text' => '内部の考え', 'thought' => true], ['text' => '回答の前半'], ['text' => 'と後半'],
            ]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 7],
        ])]);
        $answer = app(GeminiClient::class)->generate($contents, '日本語で学習を支援する');
        $this->assertSame('回答の前半と後半', $answer['content']);
        $this->assertSame('test-model', $answer['model']);
        $this->assertSame(12, $answer['input_tokens']);
        $this->assertSame(7, $answer['output_tokens']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::URL
            && $request->header('x-goog-api-key') === ['test-api-key']
            && $request->data() === ['systemInstruction' => ['parts' => [['text' => '日本語で学習を支援する']]], 'contents' => $contents]);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidResponses')]
    public function test_empty_or_incomplete_answers_are_rejected(array $response): void
    {
        Http::fake([self::URL => Http::response($response)]);
        $this->expectException(GeminiException::class);
        app(GeminiClient::class)->generate([], '学習相談');
    }

    public static function invalidResponses(): array
    {
        return [
            'no candidates' => [[]],
            'empty parts' => [['candidates' => [['content' => ['parts' => []], 'finishReason' => 'STOP']]]],
            'whitespace' => [['candidates' => [['content' => ['parts' => [['text' => " \n "]]], 'finishReason' => 'STOP']]]],
            'thought only' => [['candidates' => [['content' => ['parts' => [['text' => '内部のみ', 'thought' => true]]], 'finishReason' => 'STOP']]]],
            'token limit' => [['candidates' => [['content' => ['parts' => [['text' => '未完了']]], 'finishReason' => 'MAX_TOKENS']]]],
            'malformed parts' => [['candidates' => [['content' => ['parts' => 'invalid'], 'finishReason' => 'STOP']]]],
        ];
    }

    public function test_timeout_is_sanitized_and_not_automatically_retried(): void
    {
        $calls = 0;
        Http::fake([self::URL => function () use (&$calls) {
            $calls++;
            throw new ConnectionException('private-upstream-url-and-key');
        }]);
        try {
            app(GeminiClient::class)->generate([], '学習相談');
            $this->fail('タイムアウトが成功扱いになりました。');
        } catch (GeminiException $exception) {
            $this->assertNull($exception->upstreamStatus);
            $this->assertStringNotContainsString('private-upstream', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function test_explicit_retry_succeeds_after_temporary_failure_without_automatic_retry(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['error' => 'private-detail'], 503)->push([
            'candidates' => [['content' => ['parts' => [['text' => '再送への回答']]], 'finishReason' => 'STOP']],
        ])]);
        $client = app(GeminiClient::class);
        try {
            $client->generate([], '学習相談');
            $this->fail('一時障害が成功扱いになりました。');
        } catch (GeminiException $exception) {
            $this->assertSame(503, $exception->upstreamStatus);
            $this->assertStringNotContainsString('private-detail', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame('再送への回答', $client->generate([], '学習相談')['content']);
        Http::assertSentCount(2);
    }
}
