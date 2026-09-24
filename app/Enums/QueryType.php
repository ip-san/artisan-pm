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
}
