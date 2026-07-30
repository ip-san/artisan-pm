<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\QueryVisibility;
use App\Enums\UserStatus;
use App\Models\Member;
use App\Models\PendingUpload;
use App\Models\Query;
use App\Models\RepositoryCommitter;
use App\Models\User;
use App\Models\UserDashboardBlock;
use App\Models\Watcher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Redmine's User#destroy reassigns almost everything the user authored
 * (issues, journals, queries, time entries, wiki content, ...) to a
 * singleton "Anonymous" user, deletes private queries/watchers/tokens
 * outright, and only then hard-deletes the users row (user.rb:990-1019,
 * remove_references_before_destroy).
 *
 * This app anonymizes the row in place instead of hard-deleting it: name/
 * email/login are scrubbed and the row's status becomes Deleted, but the
 * row itself stays, so every authorship FK (issues.author_id,
 * journals.user_id, queries.user_id, time_entries.user_id,
 * wiki_pages.author_id, news.author_id, boards.author_id — all plain
 * RESTRICT constraints with no onDelete/nullOnDelete today) keeps pointing
 * at a valid row and needs no schema change and no null-handling audit
 * across the ~16 Blade views that render `->author->name`/`->user->name`.
 * The tradeoff: unlike Redmine, this app has no separate "Anonymous"
 * placeholder to distinguish "many different deleted users" from each
 * other in old Journals/Issues — each deleted user's own (now-scrubbed)
 * row remains the distinct author of record, which is arguably more
 * faithful to history than Redmine's single shared Anonymous, so this is
 * treated as an acceptable deviation rather than a gap.
 */
final class AccountDeletionService
{
    public function delete(User $user): void
    {
        Member::query()->where('user_id', $user->id)->delete();
        $user->groups()->detach();
        $user->bookmarkedProjects()->detach();
        Watcher::query()->where('user_id', $user->id)->delete();
        Query::query()->where('user_id', $user->id)->where('visibility', QueryVisibility::Private)->delete();
        UserDashboardBlock::query()->where('user_id', $user->id)->delete();
        RepositoryCommitter::query()->where('user_id', $user->id)->delete();

        PendingUpload::query()->where('user_id', $user->id)->lazy()
            ->each(fn (PendingUpload $upload) => $upload->delete());

        $placeholder = 'deleted-user-'.$user->id.'-'.Str::random(8);

        $user->forceFill([
            'name' => '削除されたユーザー',
            'email' => $placeholder.'@deleted.invalid',
            // login is NOT NULL as of the 2026-07-30 mandatory-login
            // migration, so it can no longer be scrubbed to null the way
            // email/name are — reuse the same placeholder that already
            // guarantees uniqueness for email.
            'login' => $placeholder,
            'password' => Hash::make(Str::random(40)),
            'remember_token' => null,
            'api_key' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'status' => UserStatus::Deleted,
        ])->save();
    }
}
