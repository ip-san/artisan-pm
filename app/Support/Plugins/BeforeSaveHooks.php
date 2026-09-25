<?php

declare(strict_types=1);

namespace App\Support\Plugins;

use App\Models\Issue;
use App\Models\TimeEntry;

/**
 * The before-save hooks a plugin can subscribe to (PluginManager::
 * onBeforeSave()) — this app's counterpart of Redmine's own
 * `controller_issues_new_before_save`/`_edit_before_save`/
 * `_bulk_edit_before_save` and `controller_timelog_edit_before_save`/
 * `controller_time_entries_bulk_edit_before_save`. Deliberately narrower
 * than LifecycleHooks' after-save catalog: real Redmine only ever calls a
 * `*_before_save` hook for issues and time entries (grep of Redmine
 * 7.0.0's app/controllers confirms no wiki/news/version/... equivalent
 * exists), so this app doesn't invent one either — a hook that no
 * Redmine plugin could ever have relied on isn't parity, it's a new API
 * surface this app would then have to maintain alone.
 *
 * Unlike LifecycleHooks (an ordinary Laravel event, fired-and-forgotten
 * after the change is already saved), a before-save hook runs
 * synchronously against a BeforeSaveContext wrapping the model that is
 * about to be saved — a listener may edit the model's attributes or veto
 * the save entirely (see BeforeSaveContext's own docblock).
 */
final class BeforeSaveHooks
{
    /**
     * @return array<string, array{model: class-string, description: string}> keyed by hook name
     */
    public static function catalog(): array
    {
        return [
            'issue.before_save' => ['model' => Issue::class, 'description' => '課題が保存される直前(値の変更・保存の中止が可能)'],
            'time_entry.before_save' => ['model' => TimeEntry::class, 'description' => '工数が保存される直前(値の変更・保存の中止が可能)'],
        ];
    }

    public static function isKnown(string $hook): bool
    {
        return array_key_exists($hook, self::catalog());
    }
}
