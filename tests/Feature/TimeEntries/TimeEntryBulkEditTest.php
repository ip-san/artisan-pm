<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

function timeEntryBulkEditMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a user with edit_time_entries can bulk-set activity, date, and comments across selected entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);

    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $entryA = TimeEntry::factory()->for($project)->create();
    $entryB = TimeEntry::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entryA->id, $entryB->id])
        ->set('bulkActivityId', $activity->id)
        ->set('bulkSpentOn', '2026-01-15')
        ->set('bulkComments', '一括更新済み')
        ->call('applyBulkEdit');

    expect($entryA->fresh()->activity_id)->toBe($activity->id)
        ->and($entryA->fresh()->spent_on->toDateString())->toBe('2026-01-15')
        ->and($entryA->fresh()->comments)->toBe('一括更新済み')
        ->and($entryB->fresh()->activity_id)->toBe($activity->id);
});

test('bulk edit only changes fields that were actually set', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);

    $entry = TimeEntry::factory()->for($project)->create(['comments' => 'original comment']);
    $originalActivityId = $entry->activity_id;
    $originalSpentOn = $entry->spent_on->toDateString();

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->set('bulkComments', 'updated comment')
        ->call('applyBulkEdit');

    expect($entry->fresh()->comments)->toBe('updated comment')
        ->and($entry->fresh()->activity_id)->toBe($originalActivityId)
        ->and($entry->fresh()->spent_on->toDateString())->toBe($originalSpentOn);
});

test('a user without edit_time_entries cannot apply a bulk edit to another member\'s entry', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time']);
    $otherUser = User::factory()->create();

    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $otherUser->id]);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->set('bulkActivityId', $activity->id)
        ->call('applyBulkEdit')
        ->assertForbidden();

    expect($entry->fresh()->activity_id)->not->toBe($activity->id);
});

test('bulk edit does not touch entries outside the current project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);

    $ownEntry = TimeEntry::factory()->for($project)->create();
    $foreignEntry = TimeEntry::factory()->for($otherProject)->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$ownEntry->id, $foreignEntry->id])
        ->set('bulkActivityId', $activity->id)
        ->call('applyBulkEdit');

    expect($ownEntry->fresh()->activity_id)->toBe($activity->id)
        ->and($foreignEntry->fresh()->activity_id)->not->toBe($activity->id);
});

test('bulk edit rejects an enumeration id that is not a time entry activity', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);

    $entry = TimeEntry::factory()->for($project)->create();
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->set('bulkActivityId', $priority->id)
        ->call('applyBulkEdit')
        ->assertHasErrors(['bulkActivityId']);
});

test('the bulk edit panel is not shown without edit_time_entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->assertDontSee('一括更新');
});

test('an owner of an entry can bulk edit it even without the project-wide edit_time_entries permission', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkEditMember($project, ['view_time_entries', 'log_time']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id]);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->set('bulkActivityId', $activity->id)
        ->call('applyBulkEdit');

    expect($entry->fresh()->activity_id)->toBe($activity->id);
});
