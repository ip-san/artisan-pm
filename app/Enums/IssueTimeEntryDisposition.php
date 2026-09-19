<?php

declare(strict_types=1);

namespace App\Enums;

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
}
