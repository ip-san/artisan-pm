<?php

use App\Enums\VersionStatus;
use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function defaultsMember(Project $project, User $user, array $permissions = ['view_issues', 'add_issues'], bool $assignable = true): User
{
    $role = Role::factory()->create(['permissions' => $permissions, 'assignable' => $assignable]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a new issue starts with the project default version and assignee', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $assignee = defaultsMember($project, User::factory()->create());
    $author = defaultsMember($project, User::factory()->create());
    $project->update(['default_version_id' => $version->id, 'default_assigned_to_id' => $assignee->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('fixed_version_id'))->toBe($version->id)
        ->and($component->get('assigned_to_id'))->toBe($assignee->id);
});

test('a project default version that is no longer open is not applied', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create(['status' => VersionStatus::Closed]);
    $author = defaultsMember($project, User::factory()->create());
    $project->update(['default_version_id' => $version->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('fixed_version_id'))->toBeNull();
});

test('a default assignee who lost the assignable role is not applied', function () {
    $project = Project::factory()->create();
    $assignee = defaultsMember($project, User::factory()->create(), assignable: false);
    $author = defaultsMember($project, User::factory()->create());
    $project->update(['default_assigned_to_id' => $assignee->id]);

    $component = Livewire::actingAs($author)->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('assigned_to_id'))->toBeNull();
});

test('a copied issue keeps its own version and assignee over the project defaults', function () {
    $project = Project::factory()->create();
    $defaultVersion = Version::factory()->for($project)->create();
    $ownVersion = Version::factory()->for($project)->create();
    $defaultAssignee = defaultsMember($project, User::factory()->create());
    $author = defaultsMember($project, User::factory()->create());
    $project->update(['default_version_id' => $defaultVersion->id, 'default_assigned_to_id' => $defaultAssignee->id]);

    $source = App\Models\Issue::factory()->for($project)->create([
        'fixed_version_id' => $ownVersion->id,
        'assigned_to_id' => $author->id,
    ]);

    $component = Livewire::actingAs($author)
        ->withQueryParams(['copy_from' => $source->id])
        ->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('fixed_version_id'))->toBe($ownVersion->id)
        ->and($component->get('assigned_to_id'))->toBe($author->id);
});

test('the category default assignee wins over the project default assignee', function () {
    $project = Project::factory()->create();
    $projectDefault = defaultsMember($project, User::factory()->create());
    $categoryAssignee = defaultsMember($project, User::factory()->create());
    $author = defaultsMember($project, User::factory()->create());
    $project->update(['default_assigned_to_id' => $projectDefault->id]);
    $category = IssueCategory::factory()->for($project)->create(['assigned_to_id' => $categoryAssignee->id]);
    $source = App\Models\Issue::factory()->for($project)->create(['category_id' => $category->id, 'assigned_to_id' => null]);

    $component = Livewire::actingAs($author)
        ->withQueryParams(['copy_from' => $source->id])
        ->test('issues.form', ['project' => $project->fresh()]);

    expect($component->get('assigned_to_id'))->toBe($categoryAssignee->id);
});

test('the project form saves a default version and assignee chosen from the offered options', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $assignee = defaultsMember($project, User::factory()->create());
    $project->trackers()->sync([App\Models\Tracker::factory()->create()->id]);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->set('default_version_id', $version->id)
        ->set('default_assigned_to_id', $assignee->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->default_version_id->toBe($version->id)
        ->default_assigned_to_id->toBe($assignee->id);
});

test('the project form rejects a version from another project and a non-member assignee', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $foreignVersion = Version::factory()->for(Project::factory()->create())->create();
    $outsider = User::factory()->create();
    $project->trackers()->sync([App\Models\Tracker::factory()->create()->id]);

    Livewire::actingAs($admin)->test('projects.form', ['project' => $project])
        ->set('default_version_id', $foreignVersion->id)
        ->set('default_assigned_to_id', $outsider->id)
        ->call('save')
        ->assertHasErrors(['default_version_id', 'default_assigned_to_id']);

    expect($project->fresh()->default_version_id)->toBeNull();
});

test('removing the default assignee from the project clears the default', function () {
    $project = Project::factory()->create();
    $assignee = User::factory()->create();
    defaultsMember($project, $assignee);
    $project->update(['default_assigned_to_id' => $assignee->id]);

    $project->members()->where('user_id', $assignee->id)->firstOrFail()->delete();

    expect($project->fresh()->default_assigned_to_id)->toBeNull();
});

test('deleting the default version clears the default', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $project->update(['default_version_id' => $version->id]);

    $version->delete();

    expect($project->fresh()->default_version_id)->toBeNull();
});
