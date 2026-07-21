<?php

use App\Enums\EnumerationType;
use App\Enums\RoleBuiltin;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

function timeEntryMember(Project $project, array $permissions = ['log_time', 'view_time_entries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function timeEntryActivity(): Enumeration
{
    return Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
}

test('a member with log_time can log time against a project', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $activity = timeEntryActivity();

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->set('activity_id', $activity->id)
        ->set('hours', '2.5')
        ->set('spent_on', now()->toDateString())
        ->set('comments', 'Worked on setup')
        ->call('save')
        ->assertRedirect(route('time-entries.index', $project));

    $entry = TimeEntry::firstOrFail();

    expect($entry->project_id)->toBe($project->id)
        ->and($entry->user_id)->toBe($user->id)
        ->and((float) $entry->hours)->toBe(2.5)
        ->and($entry->comments)->toBe('Worked on setup');
});

test('a member without log_time cannot access the time entry form', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project, ['view_time_entries']);

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->assertForbidden();
});

test('a member without edit_time_entries cannot select another member as the entry owner', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $otherMember = timeEntryMember($project);
    $activity = timeEntryActivity();

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->set('user_id', $otherMember->id)
        ->set('activity_id', $activity->id)
        ->set('hours', '1')
        ->set('spent_on', now()->toDateString())
        ->call('save');

    $entry = TimeEntry::firstOrFail();

    expect($entry->user_id)->toBe($user->id);
});

test('a member with edit_time_entries can log time on behalf of another member', function () {
    $project = Project::factory()->create();
    $manager = timeEntryMember($project, ['log_time', 'view_time_entries', 'edit_time_entries']);
    $otherMember = timeEntryMember($project);
    $activity = timeEntryActivity();

    Livewire::actingAs($manager)
        ->test('time-entries.form', ['project' => $project])
        ->set('user_id', $otherMember->id)
        ->set('activity_id', $activity->id)
        ->set('hours', '3')
        ->set('spent_on', now()->toDateString())
        ->call('save');

    $entry = TimeEntry::firstOrFail();

    expect($entry->user_id)->toBe($otherMember->id);
});

test('the entry owner can edit their own time entry', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $activity = timeEntryActivity();
    $entry = TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'hours' => 1]);

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project, 'timeEntry' => $entry])
        ->set('hours', '4')
        ->call('save');

    expect($entry->fresh()->hours)->toEqualWithDelta(4, 0.001);
});

test('a member cannot edit another members time entry without edit_time_entries', function () {
    $project = Project::factory()->create();
    $owner = timeEntryMember($project);
    $otherUser = timeEntryMember($project);
    $entry = TimeEntry::factory()->for($project)->for($owner)->create();

    Livewire::actingAs($otherUser)
        ->test('time-entries.form', ['project' => $project, 'timeEntry' => $entry])
        ->assertForbidden();
});

test('the time entry list filters by activity and reports a hours total', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $activityA = timeEntryActivity();
    $activityB = timeEntryActivity();

    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activityA->id, 'hours' => 2]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activityB->id, 'hours' => 5]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->call('addFilter', 'activity_id')
        ->set('filterOperators.activity_id', '=')
        ->set('filterValues.activity_id.0', $activityA->id)
        ->call('applyFilters');

    $entries = $component->get('timeEntries');

    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->hours)->toBe(2.0)
        ->and($component->get('totalHours'))->toBe('2.00');
});

test('grouping time entries buckets by activity with per group subtotals', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $activityA = timeEntryActivity();
    $activityB = timeEntryActivity();

    TimeEntry::factory()->for($project)->for($user)->count(2)->create(['activity_id' => $activityA->id, 'hours' => 1]);
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activityB->id, 'hours' => 3]);

    $component = Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('groupBy', 'activity_id');

    $grouped = $component->get('groupedTimeEntries');

    expect($grouped->get($activityA->name))->toHaveCount(2)
        ->and($grouped->get($activityB->name))->toHaveCount(1);
});

test('a project member can log time against a specific issue', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $issue = Issue::factory()->for($project)->create();
    $activity = timeEntryActivity();

    Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->set('issue_id', $issue->id)
        ->set('activity_id', $activity->id)
        ->set('hours', '1.5')
        ->set('spent_on', now()->toDateString())
        ->call('save');

    $entry = TimeEntry::firstOrFail();

    expect($entry->issue_id)->toBe($issue->id);
});

test('anonymous visitors granted view_time_entries can view the list on a public project', function () {
    Role::factory()->create([
        'builtin' => RoleBuiltin::Anonymous->value,
        'permissions' => ['view_time_entries'],
    ]);
    $publicProject = Project::factory()->create();

    expect(Gate::forUser(null)->allows('viewAny', [TimeEntry::class, $publicProject]))->toBeTrue();
});

test('the issue picker includes the currently selected issue even outside the 100 most recent', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $shared = [
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ];
    $oldIssue = Issue::factory()->for($project)->create($shared);
    Issue::factory()->for($project)->count(100)->create($shared);

    $component = Livewire::actingAs($user)
        ->test('time-entries.form', ['project' => $project])
        ->set('issue_id', $oldIssue->id);

    expect($component->get('projectIssues')->pluck('id'))->toContain($oldIssue->id);
});

test('csv export streams a csv containing the filtered time entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryMember($project);
    $activity = timeEntryActivity();
    TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->call('exportCsv')
        ->assertFileDownloaded("{$project->identifier}-time_entries.csv");
});

test('a member with own-only time entry visibility only sees their own entries', function () {
    $project = Project::factory()->create();
    $activity = timeEntryActivity();
    $role = Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'own']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $mine = TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id]);
    $notMine = TimeEntry::factory()->for($project)->create(['activity_id' => $activity->id]);

    $ids = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->get('timeEntries')->pluck('id');

    expect($ids)->toContain($mine->id)->not->toContain($notMine->id);
});

test('a member with all time entry visibility sees every entry', function () {
    $project = Project::factory()->create();
    $activity = timeEntryActivity();
    $role = Role::factory()->create(['permissions' => ['view_time_entries'], 'time_entries_visibility' => 'all']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $other = TimeEntry::factory()->for($project)->create(['activity_id' => $activity->id]);

    $ids = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->get('timeEntries')->pluck('id');

    expect($ids)->toContain($other->id);
});
