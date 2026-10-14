<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;
use App\Models\News;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Support\Collection;

/**
 * Watching a news item, forum topic or wiki page (Redmine's
 * acts_as_watchable): the viewer's own watch toggle, and the watcher
 * list a manager edits from the project's members. Authorization stays
 * with the caller — each object type gates these with its own policy.
 */
final class WatcherService
{
    public function toggle(News|Message|WikiPage $watchable, User $user): void
    {
        $existing = $watchable->watchers()->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();
        } else {
            $watchable->watchers()->create(['user_id' => $user->id]);
        }
    }

    public function add(News|Message|WikiPage $watchable, int $userId): void
    {
        $watchable->watchers()->firstOrCreate(['user_id' => $userId]);
    }

    public function remove(News|Message|WikiPage $watchable, int $userId): void
    {
        $watchable->watchers()->where('user_id', $userId)->delete();
    }

    /**
     * The watcher picker's suggestions: a name/email search over the
     * members not yet watching, ten at a time (the picker is an
     * autocomplete, not a full list).
     *
     * @param  Collection<int, User>  $members
     * @param  Collection<int, int>  $watchingUserIds
     * @return Collection<int, User>
     */
    public function candidates(Collection $members, Collection $watchingUserIds, string $search): Collection
    {
        $search = mb_strtolower(trim($search));

        return $members
            ->reject(fn (User $user) => $watchingUserIds->contains($user->id))
            ->filter(fn (User $user) => $search === '' || str_contains(mb_strtolower($user->name.' '.$user->email), $search))
            ->take(10)
            ->values();
    }
}
