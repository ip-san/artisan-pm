<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EnumerationType;
use App\Enums\MailNotificationOption;
use App\Enums\UserStatus;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\News;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * @param  ?Journal  $journal  the update's journal: an assignee change
     *                             recorded on it makes the previous assignee
     *                             involved too (Redmine's previous_assignee)
     * @return Collection<int, User>
     */
    /**
     * @param  array<int, string>|string  $eventKey  one or more candidate event
     *                                               keys this journal matches (Redmine's Journal#send_notification
     *                                               ORs several conditions together — e.g. an update that only
     *                                               changed the status matches both 'issue_updated' and
     *                                               'issue_status_updated'; either being enabled is enough).
     *                                               A bare string is accepted for the simple 'issue_added' case.
     */
    public static function forIssue(Issue $issue, array|string $eventKey, User $actor, array $mentionedLogins = [], ?Journal $journal = null): Collection
    {
        $eventKeys = is_array($eventKey) ? $eventKey : [$eventKey];

        if (array_intersect($eventKeys, self::notifiedEvents()) === []) {
            return collect();
        }

        $watcherIds = $issue->watchers()->pluck('user_id');

        // Redmine's notified_users: the assignee — every current member of an
        // assigned group (is_or_belongs_to?) — is involved in the issue like
        // its watchers, each still subject to their own mail setting.
        // Redmine's previous_assignee: whoever the update took the issue
        // away from (a group's current members) is involved as well.
        $assigneeIds = $issue->assigneeUserIds()->merge(self::previousAssigneeUserIds($journal))->unique()->values();

        $tiered = self::resolve($issue->project, $actor, $watcherIds, function (User $user) use ($issue, $assigneeIds) {
            return match ($user->mail_notification) {
                MailNotificationOption::OnlyAssigned => $assigneeIds->contains($user->id),
                MailNotificationOption::OnlyOwner => $issue->author_id === $user->id,
                default => true,
            };
        }, involvedIds: $assigneeIds);

        return $tiered->merge(self::involvedInIssue($issue, $actor, $assigneeIds))
            ->merge(self::forHighPriorityIssue($issue, $actor))
            ->merge(self::forMentionedUsers($mentionedLogins, $actor))
            ->unique('id')
            ->filter(fn (User $user) => $user->can('view', $issue))
            // Redmine's Journal#notified_users/notified_watchers/notified_mentions: a private note
            // is mailed only to those who may read it; everyone else gets nothing, not a redacted copy.
            ->when($journal?->private_notes, fn (Collection $users) => $users->filter(fn (User $user) => $user->can('viewPrivateNotes', $issue)))
            ->values();
    }

    /**
     * Redmine's Issue#notified_users and #notified_watchers beyond the project's own notified
     * users: the author and the (previous) assignees by their own User#notify_about?, whether or
     * not they are members, and every watcher whose setting is not "none", whatever its tier
     * (a watcher on only_assigned still hears about what they watch).
     *
     * @param  Collection<int, int>  $assigneeIds
     * @return Collection<int, User>
     */
    private static function involvedInIssue(Issue $issue, User $actor, Collection $assigneeIds): Collection
    {
        $watcherIds = $issue->watchers()->pluck('user_id');
        $candidateIds = $watcherIds->merge($assigneeIds)->push($issue->author_id)->filter()->unique();

        return User::query()
            ->whereIn('id', $candidateIds)
            ->where('status', UserStatus::Active)
            ->get()
            ->reject(fn (User $user) => $user->mail_notification === MailNotificationOption::None)
            ->reject(fn (User $user) => $user->id === $actor->id && $actor->no_self_notified)
            ->filter(fn (User $user) => $watcherIds->contains($user->id) || match ($user->mail_notification) {
                MailNotificationOption::OnlyAssigned => $assigneeIds->contains($user->id),
                MailNotificationOption::OnlyOwner => $issue->author_id === $user->id,
                MailNotificationOption::OnlyMyWatches => false,
                default => true,
            })
            ->values();
    }

    /**
     * The users the issue was assigned to before the change $journal
     * records: the previous user, or the current members of the previous
     * group. Empty when the journal did not change the assignee.
     *
     * @return Collection<int, int>
     */
    private static function previousAssigneeUserIds(?Journal $journal): Collection
    {
        if ($journal === null) {
            return collect();
        }

        $details = $journal->loadMissing('details')->details
            ->filter(fn (JournalDetail $detail) => $detail->property === 'attr' && filled($detail->old_value));

        $userIds = $details->where('prop_key', 'assigned_to_id')->pluck('old_value')->map(fn ($id) => (int) $id);
        $groupIds = $details->where('prop_key', 'assigned_to_group_id')->pluck('old_value')->map(fn ($id) => (int) $id);

        if ($groupIds->isNotEmpty()) {
            $userIds = $userIds->merge(DB::table('group_user')->whereIn('group_id', $groupIds)->pluck('user_id')->map(fn ($id) => (int) $id));
        }

        return $userIds->unique()->values();
    }

    /**
     * Redmine's notify_about_high_priority_issues: members who asked for it
     * are told about every issue whose priority ranks above the default one,
     * whatever their general notification setting says (even `none`). The
     * caller still applies the visibility check, and the actor's own
     * no_self_notified choice holds.
     *
     * @return Collection<int, User>
     */
    private static function forHighPriorityIssue(Issue $issue, User $actor): Collection
    {
        $defaultPosition = Enumeration::query()->ofType(EnumerationType::IssuePriority)->where('is_default', true)->value('position');
        $priorityPosition = $issue->priority()->value('position');

        if ($defaultPosition === null || $priorityPosition === null || $priorityPosition <= $defaultPosition) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $issue->project->memberUserIds())
            ->where('status', UserStatus::Active)
            ->get()
            ->filter(fn (User $user) => (bool) $user->preference('notify_about_high_priority_issues'))
            ->reject(fn (User $user) => $user->id === $actor->id && $actor->no_self_notified)
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
     * The audience of the smaller project events (a forum post, a document,
     * uploaded files): like the news mail, every project member who has not
     * opted out, plus $watcherIds (a topic's watchers), narrowed to people
     * $mayView says can see the thing. Nothing goes out unless the event key
     * is switched on in `notified_events`.
     *
     * @param  callable(User): bool  $mayView
     * @param  Collection<int, int>|null  $watcherIds
     * @return Collection<int, User>
     */
    public static function forProjectEvent(Project $project, string $eventKey, User $actor, callable $mayView, ?Collection $watcherIds = null): Collection
    {
        if (! in_array($eventKey, self::notifiedEvents(), true)) {
            return collect();
        }

        return self::resolve($project, $actor, $watcherIds ?? collect(), fn (User $user) => true, allTiersRequireMembershipOrWatch: true)
            ->filter(fn (User $user) => $mayView($user))
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
     * @param  ?Collection<int, int>  $involvedIds  users involved in the thing
     *                                              without watching it (an issue's group assignee members):
     *                                              candidates and treated like watchers by the tiers
     * @return Collection<int, User>
     */
    private static function resolve(Project $project, User $actor, Collection $watcherIds, callable $eventSpecificAllows, bool $assignedAndOwnerTiersRequireWatcher = false, bool $allTiersRequireMembershipOrWatch = false, ?Collection $involvedIds = null): Collection
    {
        // Redmine's `only_my_watches` (User#notify_about?'s object.watched_by?(self))
        // checks actual Watcher rows only — unlike OnlyMyEvents it must NOT
        // also count $involvedIds (e.g. an assigned group's members who
        // haven't explicitly watched), so this is captured before the merge.
        $actualWatcherIds = $watcherIds;
        $watcherIds = $watcherIds->merge($involvedIds ?? collect())->unique()->values();

        // Direct members plus the users of member groups, like Redmine's
        // Project#notified_users (each group user has a member row there).
        $memberIds = $project->memberUserIds();

        // Members who ticked this project under the `selected` setting.
        $selectedMemberIds = $project->members()->whereNotNull('user_id')->where('mail_notification', true)->pluck('user_id');

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
            ->filter(function (User $user) use ($watcherIds, $actualWatcherIds, $memberIds, $selectedMemberIds, $eventSpecificAllows, $assignedAndOwnerTiersRequireWatcher, $allTiersRequireMembershipOrWatch) {
                $isWatcher = $watcherIds->contains($user->id);
                $isMember = $memberIds->contains($user->id);

                $tierAllows = match (true) {
                    $user->mail_notification === MailNotificationOption::None => false,
                    $allTiersRequireMembershipOrWatch => $isMember || $isWatcher,
                    $user->mail_notification === MailNotificationOption::All => $isMember || $isWatcher,
                    // Redmine's `selected`: the projects the user ticked, plus
                    // what they are involved in (an author and an assignee
                    // watch their issue, so watching covers that).
                    $user->mail_notification === MailNotificationOption::Selected => $isWatcher || $selectedMemberIds->contains($user->id),
                    $user->mail_notification === MailNotificationOption::OnlyMyEvents => $isWatcher,
                    // Redmine's `only_my_watches`: notified only when actually
                    // watching the object, regardless of membership or
                    // involvement otherwise (see $actualWatcherIds above).
                    $user->mail_notification === MailNotificationOption::OnlyMyWatches => $actualWatcherIds->contains($user->id),
                    default => $assignedAndOwnerTiersRequireWatcher ? $isWatcher : true,
                };

                return $tierAllows && $eventSpecificAllows($user);
            });
    }
}
