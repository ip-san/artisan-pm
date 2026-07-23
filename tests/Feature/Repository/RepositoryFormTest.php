<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function repositoryManager(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_changesets', 'manage_repository']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the type select only offers SCM types enabled in settings', function () {
    Setting::set('enabled_scm_types', ['git']);
    $project = Project::factory()->create();
    $user = repositoryManager($project);

    $types = Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->get('enabledTypes')
        ->pluck('value')
        ->all();

    expect($types)->toBe(['git']);
});

test('creating a repository with a disabled SCM type is rejected', function () {
    Setting::set('enabled_scm_types', ['git']);
    $project = Project::factory()->create();
    $user = repositoryManager($project);

    Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->set('type', 'svn')
        ->set('path', '/does-not-matter')
        ->call('save')
        ->assertHasErrors(['type']);

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();
});

test('editing an existing repository keeps its own type selectable even if later disabled', function () {
    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create(['type' => 'svn']);
    Setting::set('enabled_scm_types', ['git']);
    $user = repositoryManager($project);

    $types = Livewire::actingAs($user)
        ->test('repository.form', ['project' => $project])
        ->get('enabledTypes')
        ->pluck('value')
        ->all();

    expect($types)->toContain('svn');
});
