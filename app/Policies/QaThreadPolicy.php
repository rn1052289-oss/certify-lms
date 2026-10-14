<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問スレッド(QaThread)に対する認可ポリシー。
 *
 * - viewAny: admin / 受講中のstudent・coach
 * - view: adminは全件、studentは公開済資格、coachは公開済かつ担当資格
 * - create: 受講中のstudentのみ
 * - update / resolve / unresolve: 閲覧可能なスレッドの投稿者本人のみ
 * - delete: adminは全件、studentは投稿者本人かつ回答0件のみ
 */
class QaThreadPolicy
{
    public function __construct(private readonly CertificationPolicy $certificationPolicy) {}

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin
            || ($user->status === UserStatus::InProgress
                && in_array($user->role, [UserRole::Student, UserRole::Coach], true));
    }

    public function view(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if (! $this->viewAny($user)) {
            return false;
        }

        $certification = $thread->certification;

        return $certification !== null
            && $certification->status === CertificationStatus::Published
            && $this->certificationPolicy->view($user, $certification);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student
            && $user->status === UserStatus::InProgress;
    }

    public function update(User $user, QaThread $thread): bool
    {
        return $user->role === UserRole::Student
            && $thread->user_id === $user->id
            && $this->view($user, $thread);
    }

    public function delete(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $this->update($user, $thread)
            && ! $thread->replies()->exists();
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread);
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread);
    }
}
