<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookEvent: string
{
    case IssueCreated = 'issue.created';
    case IssueUpdated = 'issue.updated';
    case IssueDeleted = 'issue.deleted';
    case WikiPageCreated = 'wiki_page.created';
    case WikiPageUpdated = 'wiki_page.updated';
    case WikiPageDeleted = 'wiki_page.deleted';
    case TimeEntryCreated = 'time_entry.created';
    case TimeEntryUpdated = 'time_entry.updated';
    case TimeEntryDeleted = 'time_entry.deleted';
}
