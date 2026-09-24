<?php

declare(strict_types=1);

namespace App\Enums;

enum QueryType: string
{
    case Issue = 'issue';
    case TimeEntry = 'time_entry';
    case Project = 'project';

    /**
     * Redmine's ProjectAdminQuery: the admin project list's saved queries,
     * seen and used by administrators only.
     */
    case ProjectAdmin = 'project_admin';

    /**
     * Redmine's UserQuery: the administrator's user list, whose saved
     * queries only administrators see and use.
     */
    case User = 'user';

    /**
     * Whether the type's saved queries belong to administrators only
     * (Redmine's ProjectAdminQuery#visible? and UserQuery#visible?).
     */
    public function isAdminOnly(): bool
    {
        return $this === self::ProjectAdmin || $this === self::User;
    }
}
