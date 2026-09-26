<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a project's time entries a role can see — same shape as
 * IssueVisibility (a separate enum since the two columns are independent
 * Role settings, not because the values differ).
 *
 * A16-05 (2026-09-26, deliberate extension): Redmine's own
 * TIME_ENTRIES_VISIBILITY_OPTIONS (role.rb) has only 'all' and 'own' — it
 * has no 'default' tier, because a time entry has no author/assignee
 * concept the way an issue does. `Default` is this app's own addition to
 * keep the two visibility selects symmetrical in the UI; it currently
 * behaves identically to `All` (see AuthorizationService and
 * TimeEntryPolicy). Kept rather than removed to avoid migrating any role
 * already saved with this value — approved as an intentional divergence,
 * not a bug, by the 2026-09-26 parity audit.
 */
enum TimeEntryVisibility: string
{
    case All = 'all';
    case Default = 'default';
    case Own = 'own';
}
