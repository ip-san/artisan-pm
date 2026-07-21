<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much of a project's issue list a role can see. `Default` and `All`
 * currently behave identically — Redmine's distinction between them only
 * matters once private issues (is_private) exist, which this app doesn't
 * implement yet — so `Default` is kept only to match Redmine's own values
 * rather than silently dropping a real option from the role form.
 */
enum IssueVisibility: string
{
    case All = 'all';
    case Default = 'default';
    case Own = 'own';
}
