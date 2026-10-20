<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AiChatMessageStatus;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Services\AiChatContextService;
use App\UseCases\AiChat\StoreConversationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    public function store(StoreConversationRequest $request, StoreConversationAction $action): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        if (($data['message'] ?? '') !== '') {
            try {
                $result = $action->withMessage($request->user(), $data);
            } catch (HttpExceptionInterface $exception) {
                if ($request->expectsJson() || ! in_array($exception->getStatusCode(), [429, 503], true)) {
                    throw $exception;
                }

                return back()->withInput($request->only('message', 'source', 'section_id', 'enrollment_id'))
                    ->withErrors(['message' => $exception->getMessage()])->with('error', $exception->getMessage());
            }
            $conversation = $result['conversation'];
            if ($request->expectsJson()) {
                $status = $result['assistant_message']->status === AiChatMessageStatus::Error ? 502 : ($conversation->wasRecentlyCreated ? 201 : 200);

                return response()->json($result, $status);
            }
            if ($result['assistant_message']->status === AiChatMessageStatus::Error) {
                return redirect()->route('ai-chat.conversations.show', $conversation)->with('error', 'AI が応答できませんでした。質問は履歴に保存されています。');
            }
        } else {
            $conversation = $action($request->user(), $data);
        }
        if ($request->expectsJson()) {
            return response()->json(['conversation' => $conversation->refresh()], $conversation->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('ai-chat.conversations.show', $conversation);
    }

    public function show(Request $request, AiChatConversation $conversation, AiChatContextService $context): JsonResponse|View
    {
        $this->authorize('view', $conversation);
        $conversation->load(['messages', 'section', 'enrollment.certification']);
        if ($request->expectsJson()) {
            $data = ['conversation' => $conversation, 'messages' => $conversation->messages];
            if ($request->query('source') === 'widget') {
                $validated = $request->validate(['section_id' => ['nullable', 'ulid']]);
                $resolved = $context->resolve($request->user(), $validated['section_id'] ?? null, null);
                $data['widget_context'] = ['enrollment_id' => $resolved['enrollment']?->id, 'section_id' => $resolved['section']?->id];
            }

            return response()->json($data);
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
