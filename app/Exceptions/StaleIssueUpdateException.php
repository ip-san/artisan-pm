<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Issue;
use RuntimeException;

/**
 * Thrown by IssueService::update() when the caller's expected lock_version
 * doesn't match the issue's current one — i.e. someone else saved a change
 * after this editor loaded the form. Matches Redmine's optimistic locking
 * on Issue#lock_version (ActiveRecord::Locking), which otherwise lets a
 * second save silently overwrite the first without warning.
 */
final class StaleIssueUpdateException extends RuntimeException
{
    public function __construct(public readonly Issue $issue)
    {
        parent::__construct("Issue #{$issue->id} was modified by another user since it was loaded.");
    }
}
