<?php

use App\Enums\QueryVisibility;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * A15-07: QueriesController#edit/update/destroy and query_is_for_all.
 *
 * @param  array<int, string>  $permissions
 */
function editDeleteQueryMember(Project $project, array $permissions = ['view_issues', 'save_queries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the owner can edit their own private query in place', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project);
    $query = SavedQuery::create([
        'name' => 'Mine', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('issues.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->assertSet('newQueryName', 'Mine')
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Renamed')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(1)
        ->and($query->fresh()->name)->toBe('Renamed');
});

test('a non-owner without manage_public_queries cannot edit or delete a private query', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project);
    $outsider = editDeleteQueryMember($project);
    $query = SavedQuery::create([
        'name' => 'Private', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($outsider)
        ->test('issues.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->assertForbidden();

    Livewire::actingAs($outsider)
        ->test('issues.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);
});

test('manage_public_queries lets another member edit and delete a public project query, but not a global one', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project);
    $manager = editDeleteQueryMember($project, ['view_issues', 'save_queries', 'manage_public_queries']);

    $projectQuery = SavedQuery::create([
        'name' => 'Public project query', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);
    $globalQuery = SavedQuery::create([
        'name' => 'Public global query', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($manager)
        ->test('issues.index', ['project' => $project])
        ->call('editQuery', $projectQuery->id)
        ->assertSet('editingQueryId', $projectQuery->id);

    Livewire::actingAs($manager)
        ->test('issues.index', ['project' => $project])
        ->call('editQuery', $globalQuery->id)
        ->assertForbidden();

    Livewire::actingAs($manager)
        ->test('issues.index', ['project' => $project])
        ->call('deleteQuery', $globalQuery->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(2);
});

test('an admin can edit and delete any query, including another user\'s private one', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project);
    $admin = User::factory()->create(['is_admin' => true]);
    Member::factory()->for($project)->for($admin)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'save_queries']]));

    $query = SavedQuery::create([
        'name' => 'Private', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('issues.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(0);
});

test('a member without save_queries cannot edit or delete even their own query', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project);
    $query = SavedQuery::create([
        'name' => 'Mine', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    // save_queries revoked after the query was created.
    $owner->memberships->first()->roles->first()->update(['permissions' => ['view_issues']]);

    Livewire::actingAs($owner)
        ->test('issues.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);
});

test('checking query_is_for_all saves a project-context query with no project', function () {
    $project = Project::factory()->create();
    $user = editDeleteQueryMember($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('newQueryName', 'For all')
        ->set('newQueryIsForAll', true)
        ->call('saveQuery')
        ->assertHasNoErrors();

    $saved = SavedQuery::where('name', 'For all')->firstOrFail();

    expect($saved->project_id)->toBeNull();
});

test('query_is_for_all combined with public visibility is still forced private for a non-admin', function () {
    $project = Project::factory()->create();
    $user = editDeleteQueryMember($project, ['view_issues', 'save_queries', 'manage_public_queries']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('newQueryName', 'For all public attempt')
        ->set('newQueryVisibility', 'public')
        ->set('newQueryIsForAll', true)
        ->call('saveQuery')
        ->assertHasNoErrors();

    $saved = SavedQuery::where('name', 'For all public attempt')->firstOrFail();

    expect($saved->project_id)->toBeNull()
        ->and($saved->visibility)->toBe(QueryVisibility::Private);
});

test('editing a query to switch it from Roles to Public visibility clears its role assignments', function () {
    $project = Project::factory()->create();
    $owner = editDeleteQueryMember($project, ['view_issues', 'save_queries', 'manage_public_queries']);
    $role = Role::factory()->create(['permissions' => ['view_issues']]);

    $query = SavedQuery::create([
        'name' => 'Role-limited', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'roles',
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);
    $query->roles()->sync([$role->id]);

    Livewire::actingAs($owner)
        ->test('issues.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($query->fresh()->roles)->toHaveCount(0);
});
