<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

function timeEntryBulkDeleteMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a user with edit_time_entries can bulk delete selected entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkDeleteMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);
    $entryA = TimeEntry::factory()->for($project)->create();
    $entryB = TimeEntry::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entryA->id, $entryB->id])
        ->call('applyBulkDelete');

    expect(TimeEntry::query()->whereKey($entryA->id)->exists())->toBeFalse()
        ->and(TimeEntry::query()->whereKey($entryB->id)->exists())->toBeFalse();
});

test('a user without edit_time_entries cannot bulk delete another member\'s entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkDeleteMember($project, ['view_time_entries', 'log_time']);
    $otherUser = User::factory()->create();
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $otherUser->id]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->call('applyBulkDelete')
        ->assertForbidden();

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

test('bulk delete does not touch entries outside the current project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = timeEntryBulkDeleteMember($project, ['view_time_entries', 'log_time', 'edit_time_entries']);

    $ownEntry = TimeEntry::factory()->for($project)->create();
    $foreignEntry = TimeEntry::factory()->for($otherProject)->create();

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$ownEntry->id, $foreignEntry->id])
        ->call('applyBulkDelete');

    expect(TimeEntry::query()->whereKey($ownEntry->id)->exists())->toBeFalse()
        ->and(TimeEntry::query()->whereKey($foreignEntry->id)->exists())->toBeTrue();
});

test('an owner of an entry can bulk delete it even without the project-wide edit_time_entries permission', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkDeleteMember($project, ['view_time_entries', 'log_time']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->call('applyBulkDelete');

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('the bulk delete button is not shown without edit_time_entries', function () {
    $project = Project::factory()->create();
    $user = timeEntryBulkDeleteMember($project, ['view_time_entries', 'log_time']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('time-entries.index', ['project' => $project])
        ->set('selected', [$entry->id])
        ->assertDontSee('を削除');
});
