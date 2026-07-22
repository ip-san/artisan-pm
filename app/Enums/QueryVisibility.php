<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Matches Redmine's Query::VISIBILITY_PRIVATE/ROLES/PUBLIC. Roles-scoped
 * visibility additionally requires at least one row in the query_role
 * pivot — enforced at the form layer, not by a DB constraint.
 */
enum QueryVisibility: string
{
    case Private = 'private';
    case Roles = 'roles';
    case Public = 'public';
}
