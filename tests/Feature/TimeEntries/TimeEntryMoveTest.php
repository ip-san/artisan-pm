<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{0: Project, 1: Project, 2: User, 3: Enumeration}
 */
function moveScenario(array $targetPermissions = ['view_project', 'view_issues', 'log_time', 'view_time_entries', 'edit_time_entries']): array
{
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $user = User::factory()->create();
    $everything = ['view_project', 'view_issues', 'log_time', 'view_time_entries', 'edit_time_entries'];
    Member::factory()->for($source)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $everything]));
    Member::factory()->for($target)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $targetPermissions]));
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    return [$source, $target, $user, $activity];
}

function moveIssue(Project $project): Issue
{
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

function moveEntry(Project $project, User $user, Enumeration $activity, ?Issue $issue = null): TimeEntry
{
    return TimeEntry::factory()->for($project)->for($user)->create(['activity_id' => $activity->id, 'issue_id' => $issue?->id, 'hours' => 2]);
}

test('an entry can be moved to another project the user may log time in', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry])
        ->set('project_id', $target->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('time-entries.index', $target));

    expect($entry->fresh()->project_id)->toBe($target->id);
});

test('moving an entry drops an issue that belongs to the old project', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity, moveIssue($source));

    $form = Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry])
        ->assertSet('issue_id', $entry->issue_id)
        ->set('project_id', $target->id)
        ->assertSet('issue_id', null);

    $form->call('save')->assertHasNoErrors();
    expect($entry->fresh()->issue_id)->toBeNull();
});

test('an issue of the target project can be picked after the move', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity);
    $targetIssue = moveIssue($target);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry])
        ->set('project_id', $target->id)
        ->set('issue_id', $targetIssue->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($entry->fresh()->only(['project_id', 'issue_id']))->toBe(['project_id' => $target->id, 'issue_id' => $targetIssue->id]);
});

test('an issue of the old project is rejected for the new project', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $sourceIssue = moveIssue($source);
    $entry = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry])
        ->set('project_id', $target->id)
        ->set('issue_id', $sourceIssue->id)
        ->call('save')
        ->assertHasErrors(['issue_id']);
});

test('a project without log_time is not offered and cannot be forced', function () {
    [$source, $target, $user, $activity] = moveScenario(['view_project', 'view_issues']);
    $entry = moveEntry($source, $user, $activity);

    $form = Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry]);
    expect($form->get('moveTargets')->pluck('id')->all())->toBe([$source->id]);

    $form->set('project_id', $target->id)->call('save')->assertForbidden();
    expect($entry->fresh()->project_id)->toBe($source->id);
});

test('the project field only appears when there is somewhere to move to', function () {
    [$source, , $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $source, 'timeEntry' => $entry])->assertSee('プロジェクト');
    Livewire::actingAs($user)->test('time-entries.form', ['project' => $source])->assertDontSee('wire:model.live="project_id"', false);
});

test('bulk edit moves entries to another project and detaches their issues', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $withIssue = moveEntry($source, $user, $activity, moveIssue($source));
    $without = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $source])
        ->set('selected', [$withIssue->id, $without->id])
        ->set('bulkProjectId', $target->id)
        ->set('bulkIssueId', 'none')
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    expect(TimeEntry::query()->whereIn('id', [$withIssue->id, $without->id])->pluck('project_id')->unique()->all())->toBe([$target->id])
        ->and($withIssue->fresh()->issue_id)->toBeNull();
});

test('a bulk move must say what happens to entries that have an issue', function () {
    [$source, $target, $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity, moveIssue($source));

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $source])
        ->set('selected', [$entry->id])
        ->set('bulkProjectId', $target->id)
        ->call('applyBulkEdit')
        ->assertHasErrors(['bulkIssueId']);

    expect($entry->fresh()->project_id)->toBe($source->id);
});

test('bulk edit can set hours, user and a new issue', function () {
    [$source, , $user, $activity] = moveScenario();
    $teammate = User::factory()->create();
    Member::factory()->for($source)->for($teammate)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    $issue = moveIssue($source);
    $entry = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $source])
        ->set('selected', [$entry->id])
        ->set('bulkHours', '3.5')
        ->set('bulkUserId', $teammate->id)
        ->set('bulkIssueId', (string) $issue->id)
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    $entry->refresh();
    expect((float) $entry->hours)->toBe(3.5)
        ->and($entry->user_id)->toBe($teammate->id)
        ->and($entry->issue_id)->toBe($issue->id);
});

test('bulk edit rejects an issue from another project, a non-member user and a forced target', function () {
    [$source, $target, $user, $activity] = moveScenario(['view_project']);
    $foreignIssue = moveIssue($target);
    $stranger = User::factory()->create();
    $entry = moveEntry($source, $user, $activity);

    $list = Livewire::actingAs($user)->test('time-entries.index', ['project' => $source])->set('selected', [$entry->id]);

    $list->set('bulkIssueId', (string) $foreignIssue->id)->call('applyBulkEdit')->assertHasErrors(['bulkIssueId']);
    $list->set('bulkIssueId', '')->set('bulkUserId', $stranger->id)->call('applyBulkEdit')->assertHasErrors(['bulkUserId']);
    $list->set('bulkUserId', null)->set('bulkProjectId', $target->id)->call('applyBulkEdit')->assertForbidden();

    expect($entry->fresh()->project_id)->toBe($source->id);
});

test('bulk edit still requires a valid hours value', function () {
    [$source, , $user, $activity] = moveScenario();
    $entry = moveEntry($source, $user, $activity);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $source])
        ->set('selected', [$entry->id])
        ->set('bulkHours', '-1')
        ->call('applyBulkEdit')
        ->assertHasErrors(['bulkHours']);
});
