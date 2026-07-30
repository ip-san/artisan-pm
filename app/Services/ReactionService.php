<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;
use Illuminate\Support\Facades\Gate;

/**
 * Matches Redmine's Redmine::Reaction module (lib/redmine/reaction.rb):
 * `editable?` gates whether a user may toggle a reaction on an object
 * (reactions_enabled setting, logged-in user, object visible to them, and
 * the object's project still active), `visible?` is the standalone
 * "should this object's reaction count even render" check the `editable?`
 * gate is built on top of.
 */
final class ReactionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function canReact(?User $user, Issue|Journal|Message|News|NewsComment $reactable): bool
    {
        if ($user === null) {
            return false;
        }

        if (! Setting::get('reactions_enabled', true)) {
            return false;
        }

        if (! $this->isVisible($user, $reactable)) {
            return false;
        }

        return $this->project($reactable)?->isOpen() ?? false;
    }

    /**
     * Toggles the given user's reaction on/off and returns the new state
     * (true = now reacted). Matches Redmine's ReactionsController#create/
     * #destroy pair, collapsed into one idempotent action.
     */
    public function toggle(Issue|Journal|Message|News|NewsComment $reactable, User $user): bool
    {
        $existing = $reactable->reactions()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        $reactable->reactions()->create(['user_id' => $user->id]);

        return true;
    }

    private function isVisible(User $user, Issue|Journal|Message|News|NewsComment $reactable): bool
    {
        return match (true) {
            $reactable instanceof Issue => Gate::forUser($user)->allows('view', $reactable),
            $reactable instanceof Message => Gate::forUser($user)->allows('view', $reactable),
            $reactable instanceof News => Gate::forUser($user)->allows('view', $reactable),
            $reactable instanceof NewsComment => Gate::forUser($user)->allows('view', $reactable->news),
            $reactable instanceof Journal => $this->journalVisible($user, $reactable),
        };
    }

    /**
     * Matches JournalPolicy::update's own visibility half (view the parent
     * issue, and private notes require view_private_notes unless the
     * viewer wrote them) — Journal has no dedicated view-ability policy of
     * its own, since visibleJournals() on the issue show page is normally
     * what filters these out before they're ever rendered.
     */
    private function journalVisible(User $user, Journal $journal): bool
    {
        if (! Gate::forUser($user)->allows('view', $journal->issue)) {
            return false;
        }

        if ($journal->private_notes
            && $journal->user_id !== $user->id
            && ! $this->authorization->can($user, 'view_private_notes', $journal->issue->project)) {
            return false;
        }

        return true;
    }

    private function project(Issue|Journal|Message|News|NewsComment $reactable): ?Project
    {
        return match (true) {
            $reactable instanceof Issue => $reactable->project,
            $reactable instanceof Message => $reactable->board->project,
            $reactable instanceof News => $reactable->project,
            $reactable instanceof NewsComment => $reactable->news->project,
            $reactable instanceof Journal => $reactable->issue->project,
        };
    }
}
