<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageStatus;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\UseCases\AiChat\SendMessageAction;
use App\UseCases\AiChat\StoreConversationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiChatConversationController extends Controller
{
    public function index(Request $request): RedirectResponse|View
    {
        $conversation = $request->user()->aiChatConversations()->orderByDesc('last_message_at')->orderByDesc('id')->first();
        if ($conversation !== null) {
            return redirect()->route('ai-chat.conversations.show', $conversation);
        }

        return view('ai-chat.empty-state');
    }

    public function store(StoreConversationRequest $request, StoreConversationAction $action, SendMessageAction $send): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $conversation = $action($request->user(), $data);
        if (($data['message'] ?? '') !== '') {
            $result = $send($request->user(), $conversation, $data['message']);
            if ($result['assistant_message']->status === AiChatMessageStatus::Error) {
                return redirect()->route('ai-chat.conversations.show', $conversation)->with('error', 'AI が応答できませんでした。質問は履歴に保存されています。');
            }
        }
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation->refresh()], $conversation->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    public function show(Request $request, AiChatConversation $conversation): JsonResponse|View
    {
        $this->authorize('view', $conversation);
        $conversation->load(['messages', 'section', 'enrollment.certification']);
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation, 'messages' => $conversation->messages]);
        }

        return view('ai-chat.show', compact('conversation'));
    }

    public function update(UpdateConversationRequest $request, AiChatConversation $conversation): RedirectResponse|JsonResponse
    {
        $conversation->update($request->validated() + ['title_manually_edited' => true]);
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation]);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation)->with('success', 'タイトルを変更しました。');
    }

    public function destroy(Request $request, AiChatConversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();
        if ($request->expectsJson()) {
            return response()->json(null, 204);
        }

        return redirect()->route('ai-chat.index')->with('success', '会話を削除しました。');
    }
}
