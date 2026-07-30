<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;
use App\Support\Authorization\AuthorizationService;

/**
 * Matches Redmine's Journal#editable_by? — Redmine's own JournalsController
 * only ever routes :edit/:update (config/routes.rb: resources :journals,
 * :only => [:edit, :update]); there is no destroy action or
 * destroyable_by? method anywhere in Redmine's Journal model. "Deleting" a
 * comment in Redmine means editing its notes to blank — the attribute
 * changes in the same journal (if any) survive, since Journal#empty? only
 * hides a journal from the timeline when both notes and details are
 * empty. This app deliberately does not add a destroy capability Redmine
 * itself doesn't have (an earlier version of this feature did, and it let
 * edit_own_issue_notes silently erase the attribute audit trail whenever
 * a comment happened to share a journal with a status/field change).
 */
final class JournalPolicy
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * An attribute-only journal (no notes, e.g. a plain status change) has
     * no user-authored text to edit — matches Redmine's edit form, which
     * is only ever reachable from a journal that has notes.
     *
     * The visibility checks below matter independently of the Livewire
     * template only ever showing an edit control on journals the viewer
     * can already see: editingJournalId is a public Livewire property (and
     * the API's {journal} route parameter is a bare ID), so a client that
     * skips the "start editing" step and posts an arbitrary ID must still
     * be blocked server-side — the same class of gap this app's own
     * SearchService visibility fix (commit 7272494) closed. Matches
     * Redmine's Journal.visible scope (Issue.visible_condition +
     * visible_notes_condition), applied before editable_by?.
     */
    public function update(User $user, Journal $journal): bool
    {
        if (blank($journal->notes)) {
            return false;
        }

        if (! $user->can('view', $journal->issue)) {
            return false;
        }

        $project = $journal->issue->project;

        if ($journal->private_notes
            && $journal->user_id !== $user->id
            && ! $this->authorization->can($user, 'view_private_notes', $project)) {
            return false;
        }

        if ($journal->user_id === $user->id && $this->authorization->can($user, 'edit_own_issue_notes', $project)) {
            return true;
        }

        return $this->authorization->can($user, 'edit_issue_notes', $project);
    }
}
