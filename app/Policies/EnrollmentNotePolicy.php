<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講生メモ(EnrollmentNote)に対する認可ポリシー。
 *
 * - viewAny / create: 当該資格の担当コーチ / admin
 * - update / delete: 担当コーチは自分のメモのみ / admin は全件
 * - student はすべて不可
 * - 削除済み Enrollment のメモは操作不可
 */
class EnrollmentNotePolicy
{
    public function __construct(private readonly EnrollmentPolicy $enrollmentPolicy) {}

    public function viewAny(User $user, Enrollment $enrollment): bool
    {
        return ! $enrollment->trashed()
            && in_array($user->role, [UserRole::Admin, UserRole::Coach], true)
            && $this->enrollmentPolicy->view($user, $enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $this->viewAny($user, $enrollment);
    }

    public function update(User $user, EnrollmentNote $note): bool
    {
        $enrollment = $note->enrollment;

        return $enrollment !== null
            && $this->viewAny($user, $enrollment)
            && ($user->role === UserRole::Admin || $note->author_user_id === $user->id);
    }

    public function delete(User $user, EnrollmentNote $note): bool
    {
        return $this->update($user, $note);
    }
}
