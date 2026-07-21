<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Query;
use App\Models\User;

final class QueryPolicy
{
    public function view(User $user, Query $query): bool
    {
        return $query->visibleTo($user);
    }

    public function update(User $user, Query $query): bool
    {
        return $query->user_id === $user->id;
    }

    public function delete(User $user, Query $query): bool
    {
        return $query->user_id === $user->id;
    }
}
