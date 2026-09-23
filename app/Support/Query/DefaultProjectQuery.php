<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Query;
use App\Models\Setting;
use App\Models\User;

/**
 * Redmine's ProjectQuery.default: the saved project query the project list
 * opens on when the visit names no filter of its own. The user's own choice
 * comes first (when they may still see it), then the site-wide one, which
 * must be a public query so everyone can use it. Project queries are always
 * global (no project).
 */
final class DefaultProjectQuery
{
    public static function for(?User $user): ?Query
    {
        $ownId = $user?->preference('default_project_query');

        if ($ownId !== null) {
            $own = self::find((int) $ownId);

            if ($own !== null && $own->visibleTo($user)) {
                return $own;
            }
        }

        $siteId = Setting::get('default_project_query');
        $site = filled($siteId) ? self::find((int) $siteId) : null;

        return $site !== null && $site->visibility === QueryVisibility::Public ? $site : null;
    }

    private static function find(int $id): ?Query
    {
        return Query::query()
            ->where('type', QueryType::Project->value)
            ->whereNull('project_id')
            ->find($id);
    }
}
