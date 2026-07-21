<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a project's time entries a role can see — same shape as
 * IssueVisibility (a separate enum since the two columns are independent
 * Role settings, not because the values differ).
 */
enum TimeEntryVisibility: string
{
    case All = 'all';
    case Default = 'default';
    case Own = 'own';
}
