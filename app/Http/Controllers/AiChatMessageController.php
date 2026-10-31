<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageStatus;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Http\JsonResponse;

/**
 * AI 相談の質問送信 Controller。JSON 応答で AI の回答を返し、AI が応答できなかったときは 502 を返す。
 */
class AiChatMessageController extends Controller
{
    public function store(StoreMessageRequest $request, AiChatConversation $conversation, SendMessageAction $action): JsonResponse
    {
        $result = $action($request->user(), $conversation, $request->validated('content'));

        return response()->json($result, $result['assistant_message']->status === AiChatMessageStatus::Error ? 502 : 200);
    }
}
