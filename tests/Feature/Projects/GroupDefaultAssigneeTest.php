<?php

use App\Models\Group;
use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Issues\AssigneeChoice;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function groupDefaultMember(Project $project, array $permissions = ['view_issues', 'add_issues'], bool $assignable = true): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'assignable' => $assignable]));

    return $user;
}

function groupDefaultGroup(Project $project, bool $assignable = true): Group
{
    $group = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues'], 'assignable' => $assignable]));

    return $group;
}

function groupDefaultProject(): Project
{
    $project = Project::factory()->create();
    $project->trackers()->sync([Tracker::factory()->create()->id]);

    return $project;
}

test('a new issue starts with the project default group while group assignment is on', function () {
    Setting::set('issue_group_assignment', true);
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $author = groupDefaultMember($project);
    $project->update(['default_assigned_to_group_id' => $group->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('assigned_to_group_id'))->toBe($group->id)
        ->and($component->get('assigned_to_id'))->toBeNull()
        ->and($component->get('assigneeChoice'))->toBe(AssigneeChoice::forGroup($group));
});

test('the project default group is not applied while group assignment is off or it lost its assignable role', function (bool $settingOn, bool $assignable) {
    Setting::set('issue_group_assignment', $settingOn);
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project, $assignable);
    $author = groupDefaultMember($project);
    $project->update(['default_assigned_to_group_id' => $group->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('assigned_to_group_id'))->toBeNull()
        ->and($component->get('assigned_to_id'))->toBeNull();
})->with([
    'setting off' => [false, true],
    'not assignable' => [true, false],
]);

test('picking a category with a default group assigns the group', function () {
    Setting::set('issue_group_assignment', true);
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $author = groupDefaultMember($project);
    $projectDefault = groupDefaultMember($project);
    $project->update(['default_assigned_to_id' => $projectDefault->id]);
    $category = IssueCategory::factory()->for($project)->create(['assigned_to_group_id' => $group->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()])
        ->set('assigneeChoice', '')
        ->set('category_id', $category->id);

    expect($component->get('assigned_to_group_id'))->toBe($group->id)
        ->and($component->get('assigned_to_id'))->toBeNull();
});

test('a category default group is skipped once group assignment is turned off', function () {
    Setting::set('issue_group_assignment', false);
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $author = groupDefaultMember($project);
    $category = IssueCategory::factory()->for($project)->create(['assigned_to_group_id' => $group->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()])
        ->set('category_id', $category->id);

    expect($component->get('assigned_to_group_id'))->toBeNull();
});

test('the project form saves a group default assignee and a user choice replaces it', function () {
    Setting::set('issue_group_assignment', true);
    $admin = User::factory()->admin()->create();
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $member = groupDefaultMember($project);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->assertSee($group->name)
        ->set('defaultAssigneeChoice', AssigneeChoice::forGroup($group))
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->default_assigned_to_group_id->toBe($group->id)
        ->default_assigned_to_id->toBeNull();

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project->fresh()])
        ->assertSet('defaultAssigneeChoice', AssigneeChoice::forGroup($group))
        ->set('defaultAssigneeChoice', (string) $member->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->default_assigned_to_group_id->toBeNull()
        ->default_assigned_to_id->toBe($member->id);
});

test('the project form refuses a group while group assignment is off or the group is not a member', function () {
    $admin = User::factory()->admin()->create();
    $project = groupDefaultProject();
    $memberGroup = groupDefaultGroup($project);
    $outsiderGroup = Group::factory()->create();

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->assertDontSee($memberGroup->name)
        ->set('defaultAssigneeChoice', AssigneeChoice::forGroup($memberGroup))
        ->call('save')
        ->assertHasErrors(['default_assigned_to_group_id']);

    Setting::set('issue_group_assignment', true);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->set('defaultAssigneeChoice', AssigneeChoice::forGroup($outsiderGroup))
        ->call('save')
        ->assertHasErrors(['default_assigned_to_group_id']);

    expect($project->fresh()->default_assigned_to_group_id)->toBeNull();
});

test('the category form saves a group default assignee and refuses a non-member group', function () {
    Setting::set('issue_group_assignment', true);
    $project = groupDefaultProject();
    $manager = groupDefaultMember($project, ['manage_categories']);
    $group = groupDefaultGroup($project);
    $outsiderGroup = Group::factory()->create();

    Livewire::actingAs($manager)->test('issue-categories.form', ['project' => $project])
        ->set('name', 'Backend')
        ->set('assigneeChoice', AssigneeChoice::forGroup($outsiderGroup))
        ->call('save')
        ->assertHasErrors(['assigned_to_group_id']);

    Livewire::actingAs($manager)->test('issue-categories.form', ['project' => $project])
        ->assertSee($group->name)
        ->set('name', 'Backend')
        ->set('assigneeChoice', AssigneeChoice::forGroup($group))
        ->call('save')
        ->assertHasNoErrors();

    $category = IssueCategory::query()->where('name', 'Backend')->firstOrFail();

    expect($category->assigned_to_group_id)->toBe($group->id)
        ->and($category->assigned_to_id)->toBeNull();

    Livewire::actingAs($manager)->test('issue-categories.index', ['project' => $project])
        ->assertSee(__('既定の担当者: :name', ['name' => $group->name]));
});

test('removing the group from the project or deleting it clears the default', function () {
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $category = IssueCategory::factory()->for($project)->create(['assigned_to_group_id' => $group->id]);
    $project->update(['default_assigned_to_group_id' => $group->id]);

    $project->members()->where('group_id', $group->id)->firstOrFail()->delete();

    expect($project->fresh()->default_assigned_to_group_id)->toBeNull();

    $group->delete();

    expect($category->fresh()->assigned_to_group_id)->toBeNull();
});

test('the API sets, shows and clears a group default assignee', function () {
    Setting::set('issue_group_assignment', true);
    $admin = User::factory()->admin()->create();
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);
    $member = groupDefaultMember($project);

    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$project->id}", ['default_assigned_to_group_id' => $group->id])
        ->assertOk()
        ->assertJsonPath('data.default_assignee', ['id' => $group->id, 'name' => $group->name, 'type' => 'group']);

    $this->putJson("/api/v1/projects/{$project->id}", ['default_assigned_to_id' => $member->id, 'default_assigned_to_group_id' => $group->id])
        ->assertUnprocessable();

    $this->putJson("/api/v1/projects/{$project->id}", ['default_assigned_to_id' => null])->assertOk();

    expect($project->fresh()->default_assigned_to_group_id)->toBeNull();
});

test('the API refuses a group default while group assignment is off or for a non-member group', function () {
    $admin = User::factory()->admin()->create();
    $project = groupDefaultProject();
    $group = groupDefaultGroup($project);

    Passport::actingAs($admin);

    $this->putJson("/api/v1/projects/{$project->id}", ['default_assigned_to_group_id' => $group->id])
        ->assertUnprocessable()->assertJsonValidationErrors('default_assigned_to_group_id');

    Setting::set('issue_group_assignment', true);

    $this->putJson("/api/v1/projects/{$project->id}", ['default_assigned_to_group_id' => Group::factory()->create()->id])
        ->assertUnprocessable()->assertJsonValidationErrors('default_assigned_to_group_id');
});

test('the category API accepts an assignable group and refuses others', function () {
    Setting::set('issue_group_assignment', true);
    $project = groupDefaultProject();
    $manager = groupDefaultMember($project, ['manage_categories']);
    $group = groupDefaultGroup($project);
    $nonAssignableGroup = groupDefaultGroup($project, assignable: false);

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/issue_categories", ['name' => 'Ops', 'assigned_to_group_id' => $nonAssignableGroup->id])
        ->assertUnprocessable()->assertJsonValidationErrors('assigned_to_group_id');

    $id = $this->postJson("/api/v1/projects/{$project->id}/issue_categories", ['name' => 'Ops', 'assigned_to_group_id' => $group->id])
        ->assertCreated()
        ->assertJsonPath('data.assigned_to_group_id', $group->id)
        ->json('data.id');

    $this->putJson("/api/v1/issue_categories/{$id}", ['assigned_to_id' => null])->assertOk()
        ->assertJsonPath('data.assigned_to_group_id', null);
});
