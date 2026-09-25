<?php

/**
 * A15-07b: wires the edit/delete UI already built for the issue list under
 * A15-07 (Query::editableBy()/QueryPolicy, SavedQueryEditDeleteTest.php)
 * into the time entry list, Gantt, project list and user list saved-query
 * bars, which previously only supported create+load. The authorization
 * matrix itself (owner/admin/manage_public_queries) is exercised in depth
 * there; this file only checks each screen's editQuery()/deleteQuery()
 * actions are wired to the same Query::editableBy()-backed policy and
 * that a representative negative case is refused on every screen.
 */

use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function wiringMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

// --- Time entry list (project-scoped) --------------------------------

test('the time entry list can edit and delete a saved query in place, owner only', function () {
    $project = Project::factory()->create();
    $owner = wiringMember($project, ['view_time_entries', 'log_time', 'save_queries']);
    $outsider = wiringMember($project, ['view_time_entries', 'log_time', 'save_queries']);
    $query = SavedQuery::create([
        'name' => 'Mine', 'type' => 'time_entry', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['hours'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('time-entries.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->assertSet('newQueryName', 'Mine')
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Renamed')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(1)->and($query->fresh()->name)->toBe('Renamed');

    Livewire::actingAs($outsider)
        ->test('time-entries.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->assertForbidden();

    Livewire::actingAs($outsider)
        ->test('time-entries.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);

    Livewire::actingAs($owner)
        ->test('time-entries.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(0);
});

// --- Time entry list (global) -----------------------------------------

test('the global time entry list can edit and delete a saved query in place, owner only', function () {
    $owner = User::factory()->admin()->create();
    $outsider = User::factory()->create();
    $query = SavedQuery::create([
        'name' => 'Global mine', 'type' => 'time_entry', 'user_id' => $owner->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['hours'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('time-entries.global-index')
        ->call('editQuery', $query->id)
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Renamed global')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($query->fresh()->name)->toBe('Renamed global');

    Livewire::actingAs($outsider)
        ->test('time-entries.global-index')
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);
});

// --- Gantt (project-scoped, shared with Calendar via UsesSavedIssueQueriesForFiltering) --

test('the gantt can edit and delete a saved issue query in place, owner only', function () {
    $project = Project::factory()->create();
    $owner = wiringMember($project, ['view_gantt', 'view_issues', 'save_queries']);
    $outsider = wiringMember($project, ['view_gantt', 'view_issues', 'save_queries']);
    $query = SavedQuery::create([
        'name' => 'Gantt mine', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    Livewire::actingAs($owner)
        ->test('gantt.index', ['project' => $project])
        ->call('editQuery', $query->id)
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Gantt renamed')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($query->fresh()->name)->toBe('Gantt renamed');

    Livewire::actingAs($outsider)
        ->test('gantt.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    Livewire::actingAs($owner)
        ->test('gantt.index', ['project' => $project])
        ->call('deleteQuery', $query->id)
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(0);
});

test('editing a gantt query keeps its original project scope rather than the viewing scope', function () {
    $project = Project::factory()->create();
    $owner = User::factory()->admin()->create();
    Member::factory()->for($project)->for($owner)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_gantt', 'view_issues', 'save_queries']]));

    $globalQuery = SavedQuery::create([
        'name' => 'Global from gantt', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    // Editing a project-less (global) query from within a specific
    // project's Gantt must not silently narrow it to that project — this
    // trait has no query_is_for_all toggle to express that choice, so the
    // original scope is preserved regardless of where the edit happened.
    Livewire::actingAs($owner)
        ->test('gantt.index', ['project' => $project])
        ->call('editQuery', $globalQuery->id)
        ->set('newQueryName', 'Still global')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($globalQuery->fresh())
        ->project_id->toBeNull()
        ->name->toBe('Still global');
});

// --- Project list (global) --------------------------------------------

test('the project list can edit and delete a saved query in place, owner only', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($owner)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'save_queries']]));
    Member::factory()->for(Project::factory()->create())->for($outsider)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'save_queries']]));
    $query = SavedQuery::create([
        'name' => 'Project list mine', 'type' => 'project', 'user_id' => $owner->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['name'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($owner)
        ->test('projects.index')
        ->call('editQuery', $query->id)
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Renamed project query')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($query->fresh()->name)->toBe('Renamed project query');

    Livewire::actingAs($outsider)
        ->test('projects.index')
        ->call('deleteQuery', $query->id)
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);

    Livewire::actingAs($owner)
        ->test('projects.index')
        ->call('deleteQuery', $query->id)
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(0);
});

// --- User list (admin-only) --------------------------------------------

test('the user list lets an admin edit and delete any saved query in place', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $query = SavedQuery::create([
        'name' => 'User list query', 'type' => 'user', 'user_id' => $admin->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['name'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    // Redmine's UserQuery is admin-only, and Query::editableBy() grants an
    // admin edit/delete on any query — so unlike the other three screens
    // the relevant negative case is "not an admin at all", not "not the
    // owner", since only admins can reach this screen in the first place.
    Livewire::actingAs($otherAdmin)
        ->test('users.index')
        ->call('editQuery', $query->id)
        ->assertSet('editingQueryId', $query->id)
        ->set('newQueryName', 'Renamed user query')
        ->call('saveQuery')
        ->assertHasNoErrors();

    expect($query->fresh()->name)->toBe('Renamed user query');

    $nonAdmin = User::factory()->create();

    Livewire::actingAs($nonAdmin)
        ->test('users.index')
        ->assertForbidden();

    expect(SavedQuery::count())->toBe(1);

    Livewire::actingAs($admin)
        ->test('users.index')
        ->call('deleteQuery', $query->id)
        ->assertHasNoErrors();

    expect(SavedQuery::count())->toBe(0);
});
