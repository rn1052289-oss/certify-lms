<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageStatus;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChat\SendMessageAction;
use Illuminate\Http\JsonResponse;

class AiChatMessageController extends Controller
{
    public function store(StoreMessageRequest $request, AiChatConversation $conversation, SendMessageAction $action): JsonResponse
    {
        $result = $action($request->user(), $conversation, $request->validated('content'));

        return response()->json($result, $result['assistant_message']->status === AiChatMessageStatus::Error ? 502 : 200);
    }
}
