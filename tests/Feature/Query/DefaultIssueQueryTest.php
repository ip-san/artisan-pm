<?php

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Preferences\UserPreferences;
use App\Support\Query\DefaultIssueQuery;
use Livewire\Livewire;

function defaultQueryViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));

    return $user;
}

function defaultQuery(string $name, QueryVisibility $visibility, ?Project $project = null, ?User $owner = null, array $columns = ['subject']): Query
{
    return Query::query()->create([
        'name' => $name, 'type' => QueryType::Issue->value, 'user_id' => ($owner ?? User::factory()->create())->id,
        'project_id' => $project?->id, 'visibility' => $visibility->value,
        'filters' => [], 'column_names' => $columns, 'sort_criteria' => [], 'group_by' => null,
    ]);
}

test('the user default beats the project default, which beats the site default', function () {
    $project = Project::factory()->create();
    $viewer = defaultQueryViewer($project);
    $site = defaultQuery('Site', QueryVisibility::Public);
    $forProject = defaultQuery('Project', QueryVisibility::Public, $project);
    $mine = defaultQuery('Mine', QueryVisibility::Private, null, $viewer);
    Setting::set('default_issue_query', $site->id);
    $project->update(['default_issue_query_id' => $forProject->id]);

    expect(DefaultIssueQuery::for($viewer, $project)->name)->toBe('Project');

    $project->update(['default_issue_query_id' => null]);
    expect(DefaultIssueQuery::for($viewer, $project->fresh())->name)->toBe('Site');

    UserPreferences::save($viewer, ['default_issue_query' => $mine->id]);
    expect(DefaultIssueQuery::for($viewer->fresh(), $project->fresh())->name)->toBe('Mine');
});

test('project and site defaults must be public and a foreign project query never applies', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $viewer = defaultQueryViewer($project);
    $private = defaultQuery('Private', QueryVisibility::Private);
    $foreign = defaultQuery('Foreign', QueryVisibility::Public, $other);

    Setting::set('default_issue_query', $private->id);
    $project->update(['default_issue_query_id' => $foreign->id]);

    expect(DefaultIssueQuery::for($viewer, $project->fresh()))->toBeNull();
});

test('a user default they can no longer see is skipped', function () {
    $project = Project::factory()->create();
    $viewer = defaultQueryViewer($project);
    $others = defaultQuery('Not yours', QueryVisibility::Private);
    UserPreferences::save($viewer, ['default_issue_query' => $others->id]);
    $site = defaultQuery('Site', QueryVisibility::Public);
    Setting::set('default_issue_query', $site->id);

    expect(DefaultIssueQuery::for($viewer->fresh(), $project)->name)->toBe('Site');
});

test('the issue list opens on the resolved query unless the URL names its own columns', function () {
    $project = Project::factory()->create();
    $viewer = defaultQueryViewer($project);
    $forProject = defaultQuery('Project', QueryVisibility::Public, $project, null, ['subject', 'done_ratio']);
    $project->update(['default_issue_query_id' => $forProject->id]);

    expect(Livewire::actingAs($viewer)->test('issues.index', ['project' => $project->fresh()])->get('columns'))->toBe(['subject', 'done_ratio'])
        ->and(Livewire::withQueryParams(['columns' => ['tracker_id']])->actingAs($viewer)->test('issues.index', ['project' => $project->fresh()])->get('columns'))->toBe(['tracker_id']);
});

test('deleting the query clears the project default', function () {
    $project = Project::factory()->create();
    $query = defaultQuery('Doomed', QueryVisibility::Public, $project);
    $project->update(['default_issue_query_id' => $query->id]);

    $query->delete();

    expect($project->fresh()->default_issue_query_id)->toBeNull();
});

test('the project form offers only public queries and validates the choice', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $project->trackers()->attach(App\Models\Tracker::factory()->create());
    $public = defaultQuery('Public one', QueryVisibility::Public, $project);
    $private = defaultQuery('Private one', QueryVisibility::Private, $project);

    $form = Livewire::actingAs($admin)->test('projects.form', ['project' => $project]);
    expect($form->get('defaultQueryOptions')->pluck('name')->all())->toBe(['Public one']);

    $form->set('default_issue_query_id', $public->id)->call('save')->assertHasNoErrors();
    expect($project->fresh()->default_issue_query_id)->toBe($public->id);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project->fresh()])->set('default_issue_query_id', $private->id)->call('save')->assertHasErrors(['default_issue_query_id']);
});

test('the settings form stores a public site-wide query only', function () {
    $admin = User::factory()->admin()->create();
    $public = defaultQuery('Site public', QueryVisibility::Public);
    $private = defaultQuery('Site private', QueryVisibility::Private);
    $scoped = defaultQuery('Scoped', QueryVisibility::Public, Project::factory()->create());

    Livewire::actingAs($admin)->test('settings.index')->set('default_issue_query', $public->id)->call('save')->assertHasNoErrors();
    expect((int) Setting::get('default_issue_query'))->toBe($public->id);

    Livewire::actingAs($admin)->test('settings.index')->set('default_issue_query', $private->id)->call('save')->assertHasErrors(['default_issue_query']);
    Livewire::actingAs($admin)->test('settings.index')->set('default_issue_query', $scoped->id)->call('save')->assertHasErrors(['default_issue_query']);
});
