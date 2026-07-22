<?php

use App\Enums\QueryVisibility;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function queryListMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('adding a filter and applying narrows the issue list', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);

    $statusA = IssueStatus::factory()->create();
    $statusB = IssueStatus::factory()->create();
    $matching = Issue::factory()->for($project)->create(['status_id' => $statusA->id]);
    Issue::factory()->for($project)->create(['status_id' => $statusB->id]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $statusA->id)
        ->call('applyFilters');

    $ids = $component->get('issues')->pluck('id')->all();

    expect($ids)->toBe([$matching->id]);
});

test('clicking a column header sorts and toggles direction on repeat clicks', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);

    $b = Issue::factory()->for($project)->create(['subject' => 'B issue']);
    $a = Issue::factory()->for($project)->create(['subject' => 'A issue']);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('sortBy', 'subject');

    expect($component->get('issues')->pluck('id')->all())->toBe([$a->id, $b->id]);

    $component->call('sortBy', 'subject');

    expect($component->get('issues')->pluck('id')->all())->toBe([$b->id, $a->id]);
});

test('grouping buckets issues by the chosen field label', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);

    $statusA = IssueStatus::factory()->create(['name' => 'New']);
    $statusB = IssueStatus::factory()->create(['name' => 'Closed']);
    Issue::factory()->for($project)->count(2)->create(['status_id' => $statusA->id]);
    Issue::factory()->for($project)->create(['status_id' => $statusB->id]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('groupBy', 'status_id');

    $grouped = $component->get('groupedIssues');

    expect($grouped->get('New'))->toHaveCount(2)
        ->and($grouped->get('Closed'))->toHaveCount(1);
});

test('saving a query persists the current filters, columns, and sort', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project, ['view_issues', 'manage_public_queries']);
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $status->id)
        ->set('newQueryName', 'My open bugs')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'My open bugs')->firstOrFail();

    expect($saved->visibility)->toBe(QueryVisibility::Public)
        ->and($saved->filters['status_id']['operator'])->toBe('=')
        ->and($saved->filters['status_id']['values'])->toBe([$status->id]);
});

test('a user without manage_public_queries cannot make a saved query public or roles-scoped', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('newQueryName', 'Attempted public query')
        ->set('newQueryVisibility', 'public')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Attempted public query')->firstOrFail();

    expect($saved->visibility)->toBe(QueryVisibility::Private);
});

test('a roles-scoped query is visible only to members holding one of the selected roles', function () {
    $project = Project::factory()->create();
    $manager = queryListMember($project, ['view_issues', 'manage_public_queries']);
    $visibleRole = Role::factory()->create(['permissions' => ['view_issues']]);
    $otherRole = Role::factory()->create(['permissions' => ['view_issues']]);

    $inRoleUser = User::factory()->create();
    Member::factory()->for($project)->for($inRoleUser)->create()->roles()->attach($visibleRole);

    $outOfRoleUser = User::factory()->create();
    Member::factory()->for($project)->for($outOfRoleUser)->create()->roles()->attach($otherRole);

    Livewire::actingAs($manager)
        ->test('issues.index', ['project' => $project])
        ->set('newQueryName', 'Roles-scoped query')
        ->set('newQueryVisibility', 'roles')
        ->set('newQueryRoleIds', [$visibleRole->id])
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Roles-scoped query')->firstOrFail();
    expect($saved->visibility)->toBe(QueryVisibility::Roles)
        ->and($saved->roles->pluck('id')->all())->toBe([$visibleRole->id]);

    $visibleComponent = Livewire::actingAs($inRoleUser)->test('issues.index', ['project' => $project]);
    expect($visibleComponent->get('savedQueries')->pluck('name'))->toContain('Roles-scoped query');

    $hiddenComponent = Livewire::actingAs($outOfRoleUser)->test('issues.index', ['project' => $project]);
    expect($hiddenComponent->get('savedQueries')->pluck('name'))->not->toContain('Roles-scoped query');
});

test('loading a saved query restores its filters into the component', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);
    $status = IssueStatus::factory()->create();

    $saved = SavedQuery::create([
        'name' => 'Saved',
        'type' => 'issue',
        'user_id' => $user->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => ['status_id' => ['operator' => '=', 'values' => [$status->id]]],
        'column_names' => ['subject', 'status_id'],
        'sort_criteria' => [['subject', 'desc']],
        'group_by' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('loadQuery', $saved->id);

    expect($component->get('activeFilterKeys'))->toBe(['status_id'])
        ->and($component->get('columns'))->toBe(['subject', 'status_id'])
        ->and($component->get('sortKey'))->toBe('subject')
        ->and($component->get('sortDirection'))->toBe('desc');
});

test('a private query is not visible to another project member', function () {
    $project = Project::factory()->create();
    $owner = queryListMember($project);
    $otherUser = queryListMember($project);

    SavedQuery::create([
        'name' => 'Private query', 'type' => 'issue', 'user_id' => $owner->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject'],
    ]);

    $component = Livewire::actingAs($otherUser)->test('issues.index', ['project' => $project]);

    expect($component->get('savedQueries')->pluck('name'))->not->toContain('Private query');
});

test('csv export streams a csv containing the filtered issues', function () {
    $project = Project::factory()->create();
    $user = queryListMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Exportable issue']);
    Issue::factory()->for($project)->create(['subject' => 'Other issue']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject'])
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-issues.csv");
});

test('the issue list is paginated according to the configured per-page setting', function () {
    Setting::set('default_issues_per_page', 5);

    $project = Project::factory()->create();
    $user = queryListMember($project);
    Issue::factory()->for($project)->count(7)->create();

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all');

    expect($component->get('issues'))->toHaveCount(5)
        ->and($component->get('issues')->total())->toBe(7);
});

test('applying a filter resets the list back to the first page', function () {
    Setting::set('default_issues_per_page', 5);

    $project = Project::factory()->create();
    $user = queryListMember($project);
    $status = IssueStatus::factory()->create();
    Issue::factory()->for($project)->count(7)->create(['status_id' => $status->id]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('nextPage');

    expect($component->get('issues')->currentPage())->toBe(2);

    $component->call('addFilter', 'status_id')
        ->set('filterOperators.status_id', '=')
        ->set('filterValues.status_id.0', $status->id)
        ->call('applyFilters');

    expect($component->get('issues')->currentPage())->toBe(1);
});
