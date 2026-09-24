<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\TimeLog\TimeLogConstraints;

/**
 * What happens to the time entries logged against an issue that is being
 * deleted — Redmine's `todo` parameter on IssuesController#destroy.
 */
enum IssueTimeEntryDisposition: string
{
    /** Delete the entries together with the issue. */
    case Destroy = 'destroy';

    /** Keep the entries on the project, detached from any issue. */
    case Nullify = 'nullify';

    /** Move the entries to another issue of the same project. */
    case Reassign = 'reassign';

    /**
     * Whether an issue deletion may use this choice: when
     * `timelog_required_fields` names the issue, a time entry cannot exist
     * without one, so Redmine neither offers nor accepts "nullify"
     * (IssuesController#destroy, issues/destroy.html.erb).
     */
    public function isAllowedForIssueDeletion(): bool
    {
        return $this !== self::Nullify || ! in_array('issue_id', TimeLogConstraints::requiredFields(), true);
    }

    /**
     * What a deletion does with the logged time when the user doesn't
     * choose: keep the entries detached (this app's data-safe default), or
     * delete them — Redmine's default — when detaching isn't allowed.
     */
    public static function defaultForIssueDeletion(): self
    {
        return self::Nullify->isAllowedForIssueDeletion() ? self::Nullify : self::Destroy;
    }
}
