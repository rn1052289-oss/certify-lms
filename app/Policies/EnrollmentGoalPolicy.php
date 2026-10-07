<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人学習目標(EnrollmentGoal)に対する認可ポリシー。
 *
 * - view: 受講生本人 / 当該資格の担当コーチ / admin
 * - create / update / delete / markAchieved / unmarkAchieved: 受講生本人のみ
 */
class EnrollmentGoalPolicy
{
    public function __construct(private readonly EnrollmentPolicy $enrollmentPolicy) {}

    public function view(User $user, EnrollmentGoal $goal): bool
    {
        return $this->enrollmentPolicy->view($user, $goal->enrollment);
    }

    public function create(User $user, Enrollment $enrollment): bool
    {
        return $user->role === UserRole::Student
            && $enrollment->user_id === $user->id;
    }

    public function update(User $user, EnrollmentGoal $goal): bool
    {
        return $this->create($user, $goal->enrollment);
    }

    public function delete(User $user, EnrollmentGoal $goal): bool
    {
        return $this->create($user, $goal->enrollment);
    }

    public function markAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->create($user, $goal->enrollment);
    }

    public function unmarkAchieved(User $user, EnrollmentGoal $goal): bool
    {
        return $this->create($user, $goal->enrollment);
    }
}
