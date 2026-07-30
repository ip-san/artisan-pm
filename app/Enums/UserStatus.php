<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Registered = 'registered';
    case Locked = 'locked';

    /**
     * Redmine hard-deletes the users row on account deletion (reassigning
     * authored content to a singleton "Anonymous" user first). This app
     * anonymizes the row in place instead of deleting it — see
     * App\Services\AccountDeletionService — so a distinct status is needed
     * to represent "gone" without a corresponding STATUS_ANONYMOUS row.
     */
    case Deleted = 'deleted';
}
