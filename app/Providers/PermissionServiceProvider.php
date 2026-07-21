<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PermissionRequirement;
use App\Enums\ProjectModuleKey;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the core (non-plugin) permission catalog. Additional modules
 * register their own permissions here as they're built; a future plugin
 * system will call PermissionRegistry::register() the same way from its
 * own service providers.
 */
final class PermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);

        $registry->register('view_project', requirement: PermissionRequirement::None);
        $registry->register('edit_project');
        $registry->register('close_project');
        $registry->register('delete_project');
        $registry->register('select_project_modules');
        $registry->register('manage_members');
        $registry->register('add_subprojects');

        $registry->register('manage_versions', module: ProjectModuleKey::IssueTracking);
        $registry->register('manage_categories', module: ProjectModuleKey::IssueTracking);

        $registry->register('view_issues', module: ProjectModuleKey::IssueTracking, requirement: PermissionRequirement::None);
        $registry->register('add_issues', module: ProjectModuleKey::IssueTracking, requirement: PermissionRequirement::LoggedIn);
        $registry->register('edit_issues', module: ProjectModuleKey::IssueTracking);
        $registry->register('delete_issues', module: ProjectModuleKey::IssueTracking);
        $registry->register('manage_issue_relations', module: ProjectModuleKey::IssueTracking);
        $registry->register('add_issue_watchers', module: ProjectModuleKey::IssueTracking);

        $registry->register('log_time', module: ProjectModuleKey::TimeTracking, requirement: PermissionRequirement::LoggedIn);
        $registry->register('view_time_entries', module: ProjectModuleKey::TimeTracking, requirement: PermissionRequirement::None);
        $registry->register('edit_time_entries', module: ProjectModuleKey::TimeTracking);
    }
}
