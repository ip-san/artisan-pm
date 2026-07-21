<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Discriminator for the generic `enumerations` table, which holds several
 * unrelated admin-configurable lists (issue priorities, time entry
 * activities, document categories, ...) in one shape: name + position.
 */
enum EnumerationType: string
{
    case IssuePriority = 'issue_priority';
    case TimeEntryActivity = 'time_entry_activity';
    case DocumentCategory = 'document_category';
}
