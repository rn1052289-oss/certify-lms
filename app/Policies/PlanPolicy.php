<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

/**
 * プラン管理の認可ポリシー。
 * 管理画面の全操作は admin のみ許可する。
 */
class PlanPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function view(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function create(User $auth): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function update(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function delete(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function publish(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function archive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }

    public function unarchive(User $auth, Plan $plan): bool
    {
        return $auth->role === UserRole::Admin;
    }
}
