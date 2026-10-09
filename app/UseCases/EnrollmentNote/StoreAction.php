<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講生メモを新規作成する Action。
 */
final class StoreAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(Enrollment $enrollment, User $author, array $validated): EnrollmentNote
    {
        return $enrollment->notes()->create([
            'author_user_id' => $author->id,
            'body' => $validated['body'],
        ]);
    }
}
