<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function defaultColumnsMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the issue list falls back to a hardcoded default when issue_list_default_columns is unset', function () {
    $project = Project::factory()->create();
    $user = defaultColumnsMember($project);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);

    expect($component->get('columns'))->toBe(['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']);
});

test('the issue list uses the configured issue_list_default_columns setting', function () {
    Setting::set('issue_list_default_columns', ['status_id', 'subject', 'due_date']);

    $project = Project::factory()->create();
    $user = defaultColumnsMember($project);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);

    expect($component->get('columns'))->toBe(['status_id', 'subject', 'due_date']);
});

test('a columns value supplied via the URL takes precedence over the configured default', function () {
    Setting::set('issue_list_default_columns', ['status_id', 'subject']);

    $project = Project::factory()->create();
    $user = defaultColumnsMember($project);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['columns' => ['due_date', 'created_at']])
        ->test('issues.index', ['project' => $project]);

    expect($component->get('columns'))->toBe(['due_date', 'created_at']);
});

test('the global issue list prepends project_id to the configured default columns', function () {
    Setting::set('issue_list_default_columns', ['status_id', 'subject']);

    $project = Project::factory()->create();
    $user = defaultColumnsMember($project);

    $component = Livewire::actingAs($user)->test('issues.global-index');

    expect($component->get('columns'))->toBe(['project_id', 'status_id', 'subject']);
});

test('the global issue list does not duplicate project_id if it is already in the configured default', function () {
    Setting::set('issue_list_default_columns', ['project_id', 'status_id']);

    $project = Project::factory()->create();
    $user = defaultColumnsMember($project);

    $component = Livewire::actingAs($user)->test('issues.global-index');

    expect($component->get('columns'))->toBe(['project_id', 'status_id']);
});
