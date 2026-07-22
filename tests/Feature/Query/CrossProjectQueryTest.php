<?php

use App\Enums\QueryVisibility;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function crossProjectQueryMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('an admin can save a query from the global issue list as public and it has no project', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    Member::factory()->for($project)->for($admin)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    Livewire::actingAs($admin)
        ->test('issues.global-index')
        ->set('newQueryName', 'Global public query')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Global public query')->firstOrFail();

    expect($saved->project_id)->toBeNull()
        ->and($saved->visibility)->toBe(QueryVisibility::Public);
});

test('a non-admin saving a query from the global issue list is forced private', function () {
    $project = Project::factory()->create();
    $user = crossProjectQueryMember($project);

    Livewire::actingAs($user)
        ->test('issues.global-index')
        ->set('newQueryName', 'Attempted global public query')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Attempted global public query')->firstOrFail();

    expect($saved->visibility)->toBe(QueryVisibility::Private);
});

test('a public global query is visible from both the global issue list and any project issue list', function () {
    $project = Project::factory()->create();
    $viewer = crossProjectQueryMember($project);

    SavedQuery::create([
        'name' => 'Global public query', 'type' => 'issue', 'user_id' => User::factory()->create()->id,
        'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    $globalComponent = Livewire::actingAs($viewer)->test('issues.global-index');
    expect($globalComponent->get('savedQueries')->pluck('name'))->toContain('Global public query');

    $projectComponent = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project]);
    expect($projectComponent->get('savedQueries')->pluck('name'))->toContain('Global public query');
});

test('a private global query is not visible to another user from either view', function () {
    $project = Project::factory()->create();
    $owner = crossProjectQueryMember($project);
    $otherUser = crossProjectQueryMember($project);

    SavedQuery::create([
        'name' => 'Private global query', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    $globalComponent = Livewire::actingAs($otherUser)->test('issues.global-index');
    expect($globalComponent->get('savedQueries')->pluck('name'))->not->toContain('Private global query');

    $projectComponent = Livewire::actingAs($otherUser)->test('issues.index', ['project' => $project]);
    expect($projectComponent->get('savedQueries')->pluck('name'))->not->toContain('Private global query');
});

test('a roles-scoped global query is visible to a user with a matching role on any project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $matchingRole = Role::factory()->create(['permissions' => ['view_issues']]);
    $otherRole = Role::factory()->create(['permissions' => ['view_issues']]);

    $inRoleUser = User::factory()->create();
    Member::factory()->for($projectB)->for($inRoleUser)->create()->roles()->attach($matchingRole);

    $outOfRoleUser = User::factory()->create();
    Member::factory()->for($projectA)->for($outOfRoleUser)->create()->roles()->attach($otherRole);

    $query = SavedQuery::create([
        'name' => 'Roles-scoped global query', 'type' => 'issue', 'user_id' => User::factory()->create()->id,
        'project_id' => null, 'visibility' => 'roles',
        'filters' => [], 'column_names' => ['subject'],
    ]);
    $query->roles()->sync([$matchingRole->id]);

    $visibleComponent = Livewire::actingAs($inRoleUser)->test('issues.global-index');
    expect($visibleComponent->get('savedQueries')->pluck('name'))->toContain('Roles-scoped global query');

    $hiddenComponent = Livewire::actingAs($outOfRoleUser)->test('issues.global-index');
    expect($hiddenComponent->get('savedQueries')->pluck('name'))->not->toContain('Roles-scoped global query');
});

test('loading a global query from the global issue list restores its filters and columns', function () {
    $project = Project::factory()->create();
    $user = crossProjectQueryMember($project);
    $status = IssueStatus::factory()->create();

    $saved = SavedQuery::create([
        'name' => 'Saved global', 'type' => 'issue', 'user_id' => $user->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => ['status_id' => ['operator' => '=', 'values' => [$status->id]]],
        'column_names' => ['subject', 'status_id'],
        'sort_criteria' => [['subject', 'desc']],
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.global-index')
        ->call('loadQuery', $saved->id);

    expect($component->get('activeFilterKeys'))->toBe(['status_id'])
        ->and($component->get('columns'))->toBe(['subject', 'status_id'])
        ->and($component->get('sortKey'))->toBe('subject')
        ->and($component->get('sortDirection'))->toBe('desc');
});

test('loading a global query from a project-scoped issue list also works', function () {
    $project = Project::factory()->create();
    $user = crossProjectQueryMember($project);

    $saved = SavedQuery::create([
        'name' => 'Saved global', 'type' => 'issue', 'user_id' => $user->id,
        'project_id' => null, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject', 'author_id'],
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('loadQuery', $saved->id);

    expect($component->get('columns'))->toBe(['subject', 'author_id']);
});

test('a project-scoped query is not visible from the global issue list', function () {
    $project = Project::factory()->create();
    $user = crossProjectQueryMember($project);

    SavedQuery::create([
        'name' => 'Project-scoped query', 'type' => 'issue', 'user_id' => $user->id,
        'project_id' => $project->id, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    $globalComponent = Livewire::actingAs($user)->test('issues.global-index');
    expect($globalComponent->get('savedQueries')->pluck('name'))->not->toContain('Project-scoped query');
});
