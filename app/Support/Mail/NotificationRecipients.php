<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Models\Issue;
use App\Models\News;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Support\Collection;

/**
 * Resolves who receives a mail notification for a domain event, matching
 * Redmine's combination of Setting.notified_events (which event types are
 * ever mailed at all) and User#mail_notification (each user's own
 * subscription tier). Issue, Wiki page, and News events are wired up — the
 * method shape (candidate resolution + tier filter + view-policy check) is
 * reused across all three, each degrading the tier rules to match what
 * that Redmine model's own notified_users actually checks.
 */
final class NotificationRecipients
{
    /**
     * @return array<int, string>
     */
    public static function defaultNotifiedEvents(): array
    {
        return ['issue_added', 'issue_updated'];
    }

    /**
     * @return array<int, string>
     */
    private static function notifiedEvents(): array
    {
        return Setting::get('notified_events', self::defaultNotifiedEvents());
    }

    /**
     * @param  array<int, string>  $mentionedLogins  logins newly mentioned
     *                                               (`@login`) in this save — see MentionParser. Unlike the
     *                                               watcher/member candidate pool resolve() builds, a mentioned
     *                                               user is notified regardless of membership or watch status,
     *                                               matching Redmine's notified_mentions being unioned in
     *                                               alongside (not filtered by) notified_users/notified_watchers
     *                                               (mailer.rb). Still gated by the same notified_events check
     *                                               above: Redmine's own mention delivery only happens inside
     *                                               the same deliver_issue_add/deliver_issue_edit calls that
     *                                               check Setting.notified_events first.
     * @return Collection<int, User>
     */
    public static function forIssue(Issue $issue, string $eventKey, User $actor, array $mentionedLogins = []): Collection
    {
        if (! in_array($eventKey, self::notifiedEvents(), true)) {
            return collect();
        }

        $watcherIds = $issue->watchers()->pluck('user_id');

        $tiered = self::resolve($issue->project, $actor, $watcherIds, function (User $user) use ($issue) {
            return match ($user->mail_notification) {
                MailNotificationOption::OnlyAssigned => $issue->assigned_to_id === $user->id,
                MailNotificationOption::OnlyOwner => $issue->author_id === $user->id,
                default => true,
            };
        });

        return $tiered->merge(self::forMentionedUsers($mentionedLogins, $actor))
            ->unique('id')
            ->filter(fn (User $user) => $user->can('view', $issue))
            ->values();
    }

    /**
     * Matches Redmine's Mention#notified_mentions minus the visible?
     * check (left to each caller, the same way forIssue()/forWikiPage()
     * already apply their own ->can('view', ...) filter after merging) —
     * this only resolves the login set to active, mail-subscribed User
     * rows and drops the actor's own mention of themselves when
     * no_self_notified is set, the same self-exclusion resolve() applies.
     *
     * @param  array<int, string>  $logins
     * @return Collection<int, User>
     */
    public static function forMentionedUsers(array $logins, User $actor): Collection
    {
        if ($logins === []) {
            return collect();
        }

        return User::query()
            ->whereIn('login', $logins)
            ->where('status', UserStatus::Active)
            ->where('mail_notification', '!=', MailNotificationOption::None->value)
            ->get()
            ->reject(fn (User $user) => $user->id === $actor->id && $actor->no_self_notified);
    }

    /**
     * Matches Redmine's WikiContent#notified_users, which is a strictly
     * simpler audience than Issue's: Project#notified_users only ever
     * checks a member's own mail_notification flag or the user's global
     * 'all' tier — there's no assignee/owner concept for a wiki page, so
     * OnlyAssigned/OnlyOwner degrade to "only if watching" (via
     * $assignedAndOwnerTiersRequireWatcher) rather than Issue's
     * unconditional-then-narrowed-by-callback shape.
     *
     * @param  array<int, string>  $mentionedLogins  see forIssue()'s own doc
     *                                               on this parameter — same union-not-filter treatment.
     * @return Collection<int, User>
     */
    public static function forWikiPage(WikiPage $page, string $eventKey, User $actor, array $mentionedLogins = []): Collection
    {
        if (! in_array($eventKey, self::notifiedEvents(), true)) {
            return collect();
        }

        $watcherIds = $page->watchers()->pluck('user_id');

        $tiered = self::resolve($page->project, $actor, $watcherIds, fn (User $user) => true, assignedAndOwnerTiersRequireWatcher: true);

        return $tiered->merge(self::forMentionedUsers($mentionedLogins, $actor))
            ->unique('id')
            ->filter(fn (User $user) => $user->can('view', $page))
            ->values();
    }

    /**
     * Matches Redmine's News#notified_users / User#notify_about?(News):
     * unlike Issue/Wiki, EVERY tier except None reaches every project
     * member unconditionally — there's no isMember/isWatcher distinction
     * at all for the base 'news_added' mail (via
     * $allTiersRequireMembershipOrWatch). For 'news_comment_added',
     * Redmine's Mailer.deliver_news_comment_added additionally unions in
     * this News item's own watchers (`news.notified_users |
     * news.notified_watchers`) — a non-member can watch a public News
     * item and would otherwise be missed — so $watcherIds is only
     * populated for that event key.
     *
     * @return Collection<int, User>
     */
    public static function forNews(News $news, string $eventKey, User $actor): Collection
    {
        if (! in_array($eventKey, self::notifiedEvents(), true)) {
            return collect();
        }

        $watcherIds = $eventKey === 'news_comment_added' ? $news->watchers()->pluck('user_id') : collect();

        return self::resolve($news->project, $actor, $watcherIds, fn (User $user) => true, allTiersRequireMembershipOrWatch: true)
            ->filter(fn (User $user) => $user->can('view', $news))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $watcherIds
     * @param  callable(User): bool  $eventSpecificAllows  extra per-event
     *                                                     narrowing for OnlyAssigned/OnlyOwner tiers, applied after the
     *                                                     generic All/OnlyMyEvents/None tiering below
     * @param  bool  $assignedAndOwnerTiersRequireWatcher  Issue's OnlyAssigned/
     *                                                     OnlyOwner tiers are unconditionally eligible (narrowed by
     *                                                     $eventSpecificAllows instead); Wiki has no assignee/owner
     *                                                     concept, so those tiers only match via watching there —
     *                                                     matches Redmine's Project#notified_users not special-casing
     *                                                     those tiers at all, leaving watcher status (unioned
     *                                                     separately) as the only path in.
     * @param  bool  $allTiersRequireMembershipOrWatch  News goes further
     *                                                  than Wiki: EVERY tier (not just All) behaves like the All
     *                                                  tier's isMember||isWatcher check, since Redmine's
     *                                                  User#notify_about?(News) grants any non-none/non-blank
     *                                                  tier unconditionally once membership is established.
     *                                                  NOTE: this flag and $assignedAndOwnerTiersRequireWatcher
     *                                                  are mutually exclusive in the tierAllows match(true) below —
     *                                                  passing both true silently ignores the second. No current
     *                                                  caller does; a future Document/Message notification caller
     *                                                  should confirm which single flag its own Redmine
     *                                                  notified_users check actually needs.
     * @return Collection<int, User>
     */
    private static function resolve(Project $project, User $actor, Collection $watcherIds, callable $eventSpecificAllows, bool $assignedAndOwnerTiersRequireWatcher = false, bool $allTiersRequireMembershipOrWatch = false): Collection
    {
        // Direct user memberships only — the same "not group-expanded"
        // simplification Project::assignableUsers() already documents,
        // kept consistent here rather than resolving group membership
        // just for this one audience.
        $memberIds = $project->users()->pluck('users.id');

        $candidateIds = $watcherIds->merge($memberIds)->unique();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('status', UserStatus::Active)
            ->get()
            // Matches Redmine's Mailer#mail removal of the author from
            // :to/:cc: it's the *author's own* preference
            // (UserPreference#no_self_notified) that decides whether they
            // see their own changes, not a site-wide admin toggle.
            ->reject(fn (User $user) => $user->id === $actor->id && $actor->no_self_notified)
            ->filter(function (User $user) use ($watcherIds, $memberIds, $eventSpecificAllows, $assignedAndOwnerTiersRequireWatcher, $allTiersRequireMembershipOrWatch) {
                $isWatcher = $watcherIds->contains($user->id);
                $isMember = $memberIds->contains($user->id);

                $tierAllows = match (true) {
                    $user->mail_notification === MailNotificationOption::None => false,
                    $allTiersRequireMembershipOrWatch => $isMember || $isWatcher,
                    $user->mail_notification === MailNotificationOption::All => $isMember || $isWatcher,
                    // Redmine's `selected` (notify only for hand-picked
                    // projects) has no per-membership toggle in this app
                    // yet, so it degrades to OnlyMyEvents — see the enum's
                    // doc comment.
                    in_array($user->mail_notification, [MailNotificationOption::OnlyMyEvents, MailNotificationOption::Selected], true) => $isWatcher,
                    default => $assignedAndOwnerTiersRequireWatcher ? $isWatcher : true,
                };

                return $tierAllows && $eventSpecificAllows($user);
            });
    }
}
