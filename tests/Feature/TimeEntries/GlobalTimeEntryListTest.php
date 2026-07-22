<?php

use App\Enums\EnumerationType;
use App\Enums\ProjectModuleKey;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

function globalListActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

function globalListTimeEntryMember(Project $project, string $visibility = 'all'): User
{
    $role = Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => $visibility]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the global time entry list only shows entries from projects the user can view_time_entries in', function () {
    $visibleProject = Project::factory()->create();
    $hiddenProject = Project::factory()->create();
    $activity = globalListActivity();
    $user = globalListTimeEntryMember($visibleProject);

    $visible = TimeEntry::factory()->for($visibleProject)->create(['activity_id' => $activity->id, 'comments' => 'Visible entry']);
    $hidden = TimeEntry::factory()->for($hiddenProject)->create(['activity_id' => $activity->id, 'comments' => 'Hidden entry']);

    $ids = Livewire::actingAs($user)
        ->test('time-entries.global-index')
        ->instance()->timeEntries->pluck('id');

    expect($ids)->toContain($visible->id)->not->toContain($hidden->id);
});

test('the global time entry list excludes projects with time tracking disabled', function () {
    $project = Project::factory()->create();
    $activity = globalListActivity();
    $user = globalListTimeEntryMember($project);
    $project->syncModules(collect(ProjectModuleKey::cases())->reject(fn ($m) => $m === ProjectModuleKey::TimeTracking)->all());

    $entry = TimeEntry::factory()->for($project)->create(['activity_id' => $activity->id]);

    $ids = Livewire::actingAs($user)
        ->test('time-entries.global-index')
        ->instance()->timeEntries->pluck('id');

    expect($ids)->not->toContain($entry->id);
});

test('cross-project visibility is bucketed per project: all-visibility here, own-only there', function () {
    $allProject = Project::factory()->create();
    $ownProject = Project::factory()->create();
    $activity = globalListActivity();

    $user = globalListTimeEntryMember($allProject, 'all');
    Member::factory()->for($ownProject)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'own']));

    $other = User::factory()->create();
    $othersEntryInAllProject = TimeEntry::factory()->for($allProject)->for($other)->create(['activity_id' => $activity->id]);
    $othersEntryInOwnProject = TimeEntry::factory()->for($ownProject)->for($other)->create(['activity_id' => $activity->id]);
    $myEntryInOwnProject = TimeEntry::factory()->for($ownProject)->for($user)->create(['activity_id' => $activity->id]);

    $ids = Livewire::actingAs($user)
        ->test('time-entries.global-index')
        ->instance()->timeEntries->pluck('id');

    expect($ids)->toContain($othersEntryInAllProject->id)
        ->toContain($myEntryInOwnProject->id)
        ->not->toContain($othersEntryInOwnProject->id);
});

test('the global time entry list can be filtered by project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $activity = globalListActivity();
    $user = globalListTimeEntryMember($projectA);
    Member::factory()->for($projectB)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'all']));

    $entryA = TimeEntry::factory()->for($projectA)->create(['activity_id' => $activity->id]);
    $entryB = TimeEntry::factory()->for($projectB)->create(['activity_id' => $activity->id]);

    $component = Livewire::actingAs($user)->test('time-entries.global-index');
    $component->set('activeFilterKeys', ['project_id'])
        ->set('filterOperators.project_id', '=')
        ->set('filterValues.project_id', [(string) $projectA->id])
        ->call('applyFilters');

    $ids = $component->instance()->timeEntries->pluck('id');

    expect($ids)->toContain($entryA->id)->not->toContain($entryB->id);
});

test('the global time entry list totals hours across every visible project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $activity = globalListActivity();
    $user = globalListTimeEntryMember($projectA);
    Member::factory()->for($projectB)->for($user)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'all']));

    TimeEntry::factory()->for($projectA)->create(['activity_id' => $activity->id, 'hours' => 2.5]);
    TimeEntry::factory()->for($projectB)->create(['activity_id' => $activity->id, 'hours' => 1.5]);

    $totalHours = Livewire::actingAs($user)->test('time-entries.global-index')->get('totalHours');

    expect($totalHours)->toBe('4.00');
});

test('a guest is redirected to login when visiting the global time entry list', function () {
    $this->get(route('time-entries.global-index'))->assertRedirect(route('login'));
});
