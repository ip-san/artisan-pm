<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Matches Redmine's Redmine::Reaction::Reactable concern (mixed into
 * Issue/Journal/Message/News/Comment) — a `has_many :reactions,
 * as: :reactable` relation plus a helper to check whether a given user has
 * already reacted.
 */
trait HasReactions
{
    /**
     * @return MorphMany<Reaction, $this>
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    public function isReactedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->reactions->contains('user_id', $user->id);
    }
}
