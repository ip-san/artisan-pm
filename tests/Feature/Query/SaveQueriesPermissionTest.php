<?php

use App\Enums\RoleBuiltin;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function permissionSplitUser(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('a member without save_queries sees no save button and cannot save an issue query', function () {
    $project = Project::factory()->create();
    $user = permissionSplitUser($project, ['view_issues']);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->assertDontSee('クエリを保存')
        ->set('newQueryName', 'Nope')->call('saveQuery')->assertForbidden();

    expect(SavedQuery::count())->toBe(0);
});

test('with save_queries the button appears and the query is saved', function () {
    $project = Project::factory()->create();
    $user = permissionSplitUser($project, ['view_issues', 'save_queries']);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->assertSee('クエリを保存')
        ->set('newQueryName', 'Mine')->call('saveQuery')->assertHasNoErrors();

    expect(SavedQuery::where('name', 'Mine')->exists())->toBeTrue();
});

test('the time entry list is gated the same way', function () {
    $project = Project::factory()->create();
    $without = permissionSplitUser($project, ['view_time_entries']);
    $with = permissionSplitUser($project, ['view_time_entries', 'save_queries']);

    Livewire::actingAs($without)->test('time-entries.index', ['project' => $project])->assertDontSee('クエリを保存')->set('newQueryName', 'Nope')->call('saveQuery')->assertForbidden();
    Livewire::actingAs($with)->test('time-entries.index', ['project' => $project])->assertSee('クエリを保存')->set('newQueryName', 'Yes')->call('saveQuery')->assertHasNoErrors();
});

test('the cross-project lists need save_queries through any role', function () {
    $project = Project::factory()->create();
    $without = permissionSplitUser($project, ['view_issues', 'view_time_entries']);
    $with = permissionSplitUser($project, ['view_issues', 'view_time_entries', 'save_queries']);

    Livewire::actingAs($without)->test('issues.global-index')->assertDontSee('クエリを保存')->set('newQueryName', 'Nope')->call('saveQuery')->assertForbidden();
    Livewire::actingAs($without)->test('time-entries.global-index')->set('newQueryName', 'Nope')->call('saveQuery')->assertForbidden();
    Livewire::actingAs($with)->test('issues.global-index')->assertSee('クエリを保存');
    Livewire::actingAs($with)->test('time-entries.global-index')->assertSee('クエリを保存');
});

test('project search needs search_project', function () {
    $project = Project::factory()->create();
    $searcher = permissionSplitUser($project, ['view_project', 'search_project']);
    $blocked = permissionSplitUser($project, ['view_project']);

    Livewire::actingAs($searcher)->test('search.index', ['project' => $project])->assertOk();
    Livewire::actingAs($blocked)->test('search.index', ['project' => $project])->assertForbidden();
});

test('the migration grants save_queries to signed-in roles and search_project to view_project holders', function () {
    $viewer = Role::factory()->create(['permissions' => ['view_project', 'view_issues']]);
    $bare = Role::factory()->create(['permissions' => ['view_issues']]);
    $anonymous = Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value, 'permissions' => ['view_project']]);

    $migration = require database_path('migrations/'.collect(scandir(database_path('migrations')))->first(fn ($file) => str_ends_with($file, 'grant_query_and_search_permissions.php')));
    $migration->up();
    $migration->up();

    expect($viewer->fresh()->permissions)->toContain('save_queries', 'search_project')
        ->and($bare->fresh()->permissions)->toContain('save_queries')->not->toContain('search_project')
        ->and($anonymous->fresh()->permissions)->toContain('search_project')->not->toContain('save_queries')
        ->and(count(array_keys($viewer->fresh()->permissions, 'save_queries', true)))->toBe(1);
    expect(DB::table('roles')->count())->toBeGreaterThan(2);
});
