<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GeminiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini API(generateContent)の呼び出しを提供する Client。
 *
 * API キー・モデル・timeout は config `ai-chat.gemini` から読む。利用回数や課金の重複を避けるため自動再試行はしない。
 * 通信失敗・HTTP エラー・空の回答・途中終了(finishReason が STOP 以外)はすべて GeminiException にする。
 */
class GeminiClient
{
    /**
     * 会話履歴と system prompt を送り、AI の回答本文と運用記録(モデル名・トークン数・応答時間)を返す。
     *
     * @param array<int, array<string, mixed>> $contents Gemini 形式の会話履歴
     *
     * @return array{content: string, model: string, input_tokens: ?int, output_tokens: ?int, response_time_ms: int}
     *
     * @throws GeminiException
     */
    public function generate(array $contents, string $systemPrompt): array
    {
        $started = hrtime(true);
        $model = (string) config('ai-chat.gemini.model');
        try {
            // 同期生成を自動再試行すると、利用回数や課金が重複するため retry しない。
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => (string) config('ai-chat.gemini.api_key')])
                ->connectTimeout((int) config('ai-chat.gemini.connect_timeout'))
                ->timeout((int) config('ai-chat.gemini.timeout'))
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => $contents,
                ]);
        } catch (ConnectionException) {
            // 例外の URL やレスポンス本文には秘密・教材・質問が含まれ得るため保存しない。
            throw new GeminiException;
        }

        if (! $response->successful()) {
            throw new GeminiException($response->status());
        }
        $parts = $response->json('candidates.0.content.parts', []);
        $text = collect(is_array($parts) ? $parts : [])->filter(fn ($part) => is_array($part) && ! ($part['thought'] ?? false))
            ->pluck('text')->filter(fn ($text) => is_string($text))->implode('');
        if (trim($text) === '' || $response->json('candidates.0.finishReason') !== 'STOP') {
            throw new GeminiException;
        }

        return [
            'content' => $text,
            'model' => $model,
            'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'response_time_ms' => (int) round((hrtime(true) - $started) / 1000000),
        ];
    }
}
