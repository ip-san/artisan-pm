<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookEvent: string
{
    case IssueCreated = 'issue.created';
    case IssueUpdated = 'issue.updated';
    case IssueDeleted = 'issue.deleted';
}
