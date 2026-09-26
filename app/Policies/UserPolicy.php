<?php

namespace App\Policies;

use App\Models\User;

/**
 * Route binding already limits users to the current organization; these rules
 * decide what a member of that organization may do.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isStaff();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->is($user) || $actor->isStaff();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin();
    }
}
