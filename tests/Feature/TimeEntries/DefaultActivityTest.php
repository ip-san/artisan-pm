<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function timeActivity(string $name, array $attributes = []): Enumeration
{
    return Enumeration::factory()->create([
        'type' => EnumerationType::TimeEntryActivity->value,
        'name' => $name,
        ...$attributes,
    ]);
}

function roleDefaultActivityMember(Project $project, ?Enumeration $roleDefault = null, int $position = 1, array $permissions = ['log_time', 'view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create([
        'permissions' => $permissions,
        'position' => $position,
        'default_time_entry_activity_id' => $roleDefault?->id,
    ]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the only available activity is the default', function () {
    $project = Project::factory()->create();
    $only = timeActivity('Development');

    expect($project->defaultActivityId(roleDefaultActivityMember($project)))->toBe($only->id);
});

test('with no role default the global default activity is used', function () {
    $project = Project::factory()->create();
    timeActivity('Design');
    $global = timeActivity('Development', ['is_default' => true]);

    expect($project->defaultActivityId(roleDefaultActivityMember($project)))->toBe($global->id);
});

test('a role default beats the global default', function () {
    $project = Project::factory()->create();
    timeActivity('Development', ['is_default' => true]);
    $design = timeActivity('Design');

    expect($project->defaultActivityId(roleDefaultActivityMember($project, $design)))->toBe($design->id);
});

test('when several roles name a default the lowest position role wins', function () {
    $project = Project::factory()->create();
    timeActivity('Development', ['is_default' => true]);
    $first = timeActivity('Design');
    $second = timeActivity('Testing');
    $user = roleDefaultActivityMember($project, $second, position: 5);
    $extraRole = Role::factory()->create(['position' => 2, 'default_time_entry_activity_id' => $first->id]);
    Member::query()->where('user_id', $user->id)->firstOrFail()->roles()->attach($extraRole);

    expect($project->defaultActivityId($user))->toBe($first->id);
});

test('an inactive role default falls through to the global default', function () {
    $project = Project::factory()->create();
    $global = timeActivity('Development', ['is_default' => true]);
    $retired = timeActivity('Retired', ['active' => false]);
    timeActivity('Design');

    expect($project->defaultActivityId(roleDefaultActivityMember($project, $retired)))->toBe($global->id);
});

test('a role default resolves to the project override of that activity', function () {
    $project = Project::factory()->create();
    $design = timeActivity('Design');
    timeActivity('Development', ['is_default' => true]);
    $override = timeActivity('Design', ['project_id' => $project->id, 'parent_id' => $design->id]);

    expect($project->defaultActivityId(roleDefaultActivityMember($project, $design)))->toBe($override->id);
});

test('a role default for a project override that deactivated it is skipped', function () {
    $project = Project::factory()->create();
    $design = timeActivity('Design');
    $global = timeActivity('Development', ['is_default' => true]);
    timeActivity('Design', ['project_id' => $project->id, 'parent_id' => $design->id, 'active' => false]);

    expect($project->defaultActivityId(roleDefaultActivityMember($project, $design)))->toBe($global->id);
});

test('a non-member gets no role default', function () {
    $project = Project::factory()->create();
    $design = timeActivity('Design');
    $global = timeActivity('Development', ['is_default' => true]);
    Role::factory()->create(['builtin' => App\Enums\RoleBuiltin::NonMember, 'default_time_entry_activity_id' => $design->id]);

    expect($project->defaultActivityId(User::factory()->create()))->toBe($global->id);
});

test('no activities means no default', function () {
    expect(Project::factory()->create()->defaultActivityId(User::factory()->create()))->toBeNull();
});

test('the time entry form preselects the role default activity', function () {
    $project = Project::factory()->create();
    timeActivity('Development', ['is_default' => true]);
    $design = timeActivity('Design');
    $user = roleDefaultActivityMember($project, $design);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->assertSet('activity_id', $design->id);
});

test('the role form saves a shared active activity and rejects others', function () {
    $admin = User::factory()->admin()->create();
    $shared = timeActivity('Design');
    $inactive = timeActivity('Retired', ['active' => false]);
    $projectScoped = timeActivity('Scoped', ['project_id' => Project::factory()->create()->id]);
    $role = Role::factory()->create(['permissions' => ['view_issues']]);

    Livewire::actingAs($admin)->test('roles.form', ['role' => $role])
        ->set('defaultTimeEntryActivityId', $shared->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->default_time_entry_activity_id)->toBe($shared->id);

    foreach ([$inactive, $projectScoped] as $invalid) {
        Livewire::actingAs($admin)->test('roles.form', ['role' => $role])
            ->set('defaultTimeEntryActivityId', $invalid->id)
            ->call('save')
            ->assertHasErrors('defaultTimeEntryActivityId');
    }

    Livewire::actingAs($admin)->test('roles.form', ['role' => $role])
        ->set('defaultTimeEntryActivityId', null)
        ->call('save')
        ->assertHasNoErrors();

    expect($role->fresh()->default_time_entry_activity_id)->toBeNull();
});

test('deleting the activity clears the role default', function () {
    $design = timeActivity('Design');
    $role = Role::factory()->create(['default_time_entry_activity_id' => $design->id]);

    $design->delete();

    expect($role->fresh()->default_time_entry_activity_id)->toBeNull();
});
