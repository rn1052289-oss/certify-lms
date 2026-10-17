<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Http\Requests\QaThread\IndexRequest;
use App\Models\QaReply;
use App\Models\QaThread;
use App\UseCases\QaReply\DestroyAction as DestroyReplyAction;
use App\UseCases\QaThread\DestroyAction as DestroyThreadAction;
use App\UseCases\QaThread\IndexAction;
use App\UseCases\QaThread\ShowAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 管理者向け質問掲示板モデレーション Controller。
 *
 * 全資格の質問・回答を閲覧し、必要に応じて物理削除する。
 * 管理者による投稿・編集・解決状態の変更は扱わない。
 */
class QaBoardModerationController extends Controller
{
    public function index(IndexRequest $request, IndexAction $action): View
    {
        $filters = $request->validated();
        $result = $action($request->user(), $filters);

        return view('qa-thread.index', [
            'threads' => $result['threads'],
            'certifications' => $result['certifications'],
            'filters' => [
                'certification_id' => $filters['certification_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'keyword' => $filters['keyword'] ?? '',
            ],
            'publishedStatus' => CertificationStatus::Published,
        ]);
    }

    public function show(QaThread $thread, ShowAction $action): View
    {
        $this->authorize('view', $thread);

        return view('qa-thread.show', [
            'thread' => $action($thread),
        ]);
    }

    public function destroy(QaThread $thread, Request $request, DestroyThreadAction $action): RedirectResponse
    {
        $this->authorize('delete', $thread);

        $action($thread, $request->user());

        return redirect()
            ->route('admin.qa-board.index')
            ->with('success', '質問を削除しました。');
    }

    public function destroyReply(
        QaThread $thread,
        QaReply $reply,
        DestroyReplyAction $action,
    ): RedirectResponse {
        abort_unless($reply->qa_thread_id === $thread->id, 404);

        $this->authorize('delete', $reply);

        $action($reply);

        return redirect()
            ->route('admin.qa-board.show', $thread)
            ->with('success', '回答を削除しました。');
    }
}
