<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function globalActionsMember(Project $project, array $permissions, string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'time_entries_visibility' => $visibility]));

    return $user;
}

function globalActionsEntry(Project $project, User $user, float $hours = 1): TimeEntry
{
    return TimeEntry::factory()->for($project)->create([
        'user_id' => $user->id, 'hours' => $hours,
        'activity_id' => Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value])->id,
    ]);
}

test('the cross-project list offers edit and delete only where the viewer may', function () {
    $editable = Project::factory()->create();
    $readOnly = Project::factory()->create();
    $user = globalActionsMember($editable, ['view_time_entries', 'edit_time_entries']);
    Member::factory()->for($readOnly)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries']]));
    $mine = globalActionsEntry($editable, $user);
    $theirs = globalActionsEntry($readOnly, User::factory()->create());

    $page = Livewire::actingAs($user)->test('time-entries.global-index');

    $page->assertSee(route('time-entries.edit', [$editable, $mine]), false)
        ->assertDontSee(route('time-entries.edit', [$readOnly, $theirs]), false)
        ->assertSee("deleteEntry({$mine->id})", false)
        ->assertDontSee("deleteEntry({$theirs->id})", false);
});

test('an entry can be deleted from the cross-project list when allowed', function () {
    $project = Project::factory()->create();
    $user = globalActionsMember($project, ['view_time_entries', 'edit_time_entries']);
    $entry = globalActionsEntry($project, $user);

    Livewire::actingAs($user)->test('time-entries.global-index')->call('deleteEntry', $entry->id);

    expect(TimeEntry::find($entry->id))->toBeNull();
});

test('deleting is refused without permission, for an unseen entry, and for one that does not exist', function () {
    $project = Project::factory()->create();
    $viewer = globalActionsMember($project, ['view_time_entries']);
    $entry = globalActionsEntry($project, User::factory()->create());
    $hidden = globalActionsEntry(Project::factory()->private()->create(), User::factory()->create());

    Livewire::actingAs($viewer)->test('time-entries.global-index')->call('deleteEntry', $entry->id)->assertForbidden();
    Livewire::actingAs($viewer)->test('time-entries.global-index')->call('deleteEntry', $hidden->id)->assertNotFound();
    Livewire::actingAs($viewer)->test('time-entries.global-index')->call('deleteEntry', 999999)->assertNotFound();
    expect(TimeEntry::find($entry->id))->not->toBeNull()->and(TimeEntry::find($hidden->id))->not->toBeNull();
});

test('the cross-project list exports the chosen columns of the filtered rows as CSV', function () {
    $project = Project::factory()->create();
    $user = globalActionsMember($project, ['view_time_entries']);
    globalActionsEntry($project, $user, 2);
    globalActionsEntry(Project::factory()->private()->create(), User::factory()->create(), 9);

    $component = Livewire::actingAs($user)->test('time-entries.global-index')->set('columns', ['project_id', 'hours'])->call('exportCsv');
    $csv = base64_decode($component->effects['download']['content']);

    expect($csv)->toBe("\xEF\xBB\xBF".csvRow(['プロジェクト', '時間']).csvRow([$project->name, '2.00']));
});
