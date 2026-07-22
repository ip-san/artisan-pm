<?php

use App\Enums\QueryVisibility;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function crossProjectTimeEntryMember(Project $project, array $permissions = ['view_time_entries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('an admin can save a query from the global time entry list as public and it has no project', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    Member::factory()->for($project)->for($admin)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries']]));

    Livewire::actingAs($admin)
        ->test('time-entries.global-index')
        ->set('newQueryName', 'Global public time query')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Global public time query')->firstOrFail();

    expect($saved->project_id)->toBeNull()
        ->and($saved->visibility)->toBe(QueryVisibility::Public);
});

test('a non-admin saving a query from the global time entry list is forced private', function () {
    $project = Project::factory()->create();
    $user = crossProjectTimeEntryMember($project);

    Livewire::actingAs($user)
        ->test('time-entries.global-index')
        ->set('newQueryName', 'Attempted global public time query')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Attempted global public time query')->firstOrFail();

    expect($saved->visibility)->toBe(QueryVisibility::Private);
});

test('a public global time entry query is visible from both the global list and any project list', function () {
    $project = Project::factory()->create();
    $viewer = crossProjectTimeEntryMember($project);

    SavedQuery::create([
        'name' => 'Global public time query', 'type' => 'time_entry', 'user_id' => User::factory()->create()->id,
        'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['spent_on'],
    ]);

    $globalComponent = Livewire::actingAs($viewer)->test('time-entries.global-index');
    expect($globalComponent->get('savedQueries')->pluck('name'))->toContain('Global public time query');

    $projectComponent = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project]);
    expect($projectComponent->get('savedQueries')->pluck('name'))->toContain('Global public time query');
});

test('loading a global time entry query from the global list restores its filters, columns, and grouping', function () {
    $project = Project::factory()->create();
    $user = crossProjectTimeEntryMember($project);

    $saved = SavedQuery::create([
        'name' => 'Saved global time query', 'type' => 'time_entry', 'user_id' => $user->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [],
        'column_names' => ['spent_on', 'hours'],
        'sort_criteria' => [['spent_on', 'asc']],
        'group_by' => 'user_id',
    ]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.global-index')
        ->call('loadQuery', $saved->id);

    expect($component->get('columns'))->toBe(['spent_on', 'hours'])
        ->and($component->get('groupBy'))->toBe('user_id')
        ->and($component->get('sortKey'))->toBe('spent_on')
        ->and($component->get('sortDirection'))->toBe('asc');
});

test('a project-scoped time entry query is not visible from the global list', function () {
    $project = Project::factory()->create();
    $user = crossProjectTimeEntryMember($project);

    SavedQuery::create([
        'name' => 'Project-scoped time query', 'type' => 'time_entry', 'user_id' => $user->id,
        'project_id' => $project->id, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['spent_on'],
    ]);

    $globalComponent = Livewire::actingAs($user)->test('time-entries.global-index');
    expect($globalComponent->get('savedQueries')->pluck('name'))->not->toContain('Project-scoped time query');
});
