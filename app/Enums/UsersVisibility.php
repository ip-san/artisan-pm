<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Matches Redmine's Role::USERS_VISIBILITY_OPTIONS (role.rb). Governs how
 * much of the site's user base a role's holder can search/see outside
 * their own projects — e.g. the "add member" autocomplete on
 * projects/members. Redmine calls the restricted tier
 * 'members_of_visible_projects'; kept identical here rather than
 * shortened, since it's the literal column value.
 */
enum UsersVisibility: string
{
    case All = 'all';
    case MembersOfVisibleProjects = 'members_of_visible_projects';
}
