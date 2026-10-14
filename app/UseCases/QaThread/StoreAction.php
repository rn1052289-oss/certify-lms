<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 受講生による質問スレッドの新規投稿ユースケース。
 *
 * 公開済み資格に限り、未解決状態で質問を作成する。
 * 投稿権限と入力値の検証は StoreRequest で行う。
 */
final class StoreAction
{
    /**
     * @param array{certification_id: string, title: string, body: string} $validated
     */
    public function __invoke(User $author, array $validated): QaThread
    {
        $certification = Certification::query()
            ->published()
            ->where('id', $validated['certification_id'])
            ->first();

        if ($certification === null) {
            throw new NotFoundHttpException('指定された資格には質問を投稿できません。');
        }

        return QaThread::create([
            'certification_id' => $certification->id,
            'user_id' => $author->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => QaThreadStatus::Unresolved->value,
            'resolved_at' => null,
        ]);
    }
}
