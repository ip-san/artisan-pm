<?php

declare(strict_types=1);

namespace App\Services;

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomFieldFormat;
use App\Enums\QueryVisibility;
use App\Enums\UserStatus;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\PendingUpload;
use App\Models\Project;
use App\Models\Query;
use App\Models\Reaction;
use App\Models\RepositoryCommitter;
use App\Models\User;
use App\Models\UserDashboardBlock;
use App\Models\Watcher;
use App\Models\Webhook;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

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
 *
 * What Redmine removes rather than reassigns is removed here too (B'-02c):
 * the user's own custom field values and their attachment files (Redmine
 * deletes them with the users row; here the row stays, so they would stay
 * downloadable), other records' user-format values pointing at the user,
 * tokens (API/Atom keys and Passport tokens), preferences, reactions,
 * webhooks, and the user as assignee of issues and categories.
 */
final class AccountDeletionService
{
    public function delete(User $user): void
    {
        Member::query()->where('user_id', $user->id)->delete();
        Project::query()->where('default_assigned_to_id', $user->id)->update(['default_assigned_to_id' => null]);
        IssueCategory::query()->where('assigned_to_id', $user->id)->update(['assigned_to_id' => null]);
        // Like Redmine's update_all: no journal entry for the unassignment.
        Issue::query()->where('assigned_to_id', $user->id)->update(['assigned_to_id' => null]);
        $user->groups()->detach();
        $user->additionalEmails()->delete();
        $user->bookmarkedProjects()->detach();
        Watcher::query()->where('user_id', $user->id)->delete();
        Query::query()->where('user_id', $user->id)->where('visibility', QueryVisibility::Private)->delete();
        UserDashboardBlock::query()->where('user_id', $user->id)->delete();
        RepositoryCommitter::query()->where('user_id', $user->id)->delete();
        Reaction::query()->where('user_id', $user->id)->delete();
        Webhook::query()->where('user_id', $user->id)->delete();
        $this->deletePassportTokens($user);

        $user->customFieldValues()->delete();
        // Deleting the media removes the files from disk.
        $user->clearMediaCollection(AttachmentFormat::COLLECTION);
        CustomFieldValue::query()
            ->whereIn('custom_field_id', CustomField::query()->where('field_format', CustomFieldFormat::User)->select('id'))
            ->where('value_int', $user->id)
            ->delete();

        PendingUpload::query()->where('user_id', $user->id)->lazy()
            ->each(fn (PendingUpload $upload) => $upload->delete());

        $placeholder = 'deleted-user-'.$user->id.'-'.Str::random(8);

        $user->forceFill([
            'name' => '削除されたユーザー',
            // Cleared, or the saving hook would rebuild the real name from them.
            'firstname' => null,
            'lastname' => null,
            'email' => $placeholder.'@deleted.invalid',
            // login is NOT NULL as of the 2026-07-30 mandatory-login
            // migration, so it can no longer be scrubbed to null the way
            // email/name are — reuse the same placeholder that already
            // guarantees uniqueness for email.
            'login' => $placeholder,
            'password' => Hash::make(Str::random(40)),
            'remember_token' => null,
            'api_key' => null,
            'atom_key' => null,
            'preferences' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'status' => UserStatus::Deleted,
        ])->save();
    }

    private function deletePassportTokens(User $user): void
    {
        $accessTokenIds = Passport::tokenModel()::query()->where('user_id', $user->id)->pluck('id');

        Passport::refreshTokenModel()::query()->whereIn('access_token_id', $accessTokenIds)->delete();
        Passport::tokenModel()::query()->whereIn('id', $accessTokenIds)->delete();
        Passport::authCodeModel()::query()->where('user_id', $user->id)->delete();
    }
}
