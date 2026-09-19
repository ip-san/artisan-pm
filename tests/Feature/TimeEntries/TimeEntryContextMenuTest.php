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
function timeEntryMenuMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('right-clicking selects the entry unless it is already part of the selection', function () {
    $project = Project::factory()->create();
    $user = timeEntryMenuMember($project, ['view_time_entries', 'edit_time_entries']);
    [$a, $b] = TimeEntry::factory()->for($project)->count(2)->create();

    $list = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->set('selected', [(string) $a->id])->call('openContextMenu', $b->id);
    expect($list->get('selected'))->toBe([(string) $b->id]);

    $list->set('selected', [(string) $a->id, (string) $b->id])->call('openContextMenu', $a->id);
    expect($list->get('selected'))->toBe([(string) $a->id, (string) $b->id]);
});

test('an entry the user may not edit, or from another project, cannot be picked', function () {
    $project = Project::factory()->create();
    $user = timeEntryMenuMember($project, ['view_time_entries', 'edit_own_time_entries']);
    $notMine = TimeEntry::factory()->for($project)->create();
    $foreign = TimeEntry::factory()->for(Project::factory()->create())->create();

    $list = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project]);
    $list->call('openContextMenu', $notMine->id)->assertForbidden();
    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->call('openContextMenu', $foreign->id)->assertNotFound();
});

test('a menu pick sets the activity on the whole selection and ignores other form input', function () {
    $project = Project::factory()->create();
    $user = timeEntryMenuMember($project, ['view_time_entries', 'edit_time_entries']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    [$a, $b] = TimeEntry::factory()->for($project)->count(2)->create(['comments' => 'keep']);

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->set('bulkComments', 'overwrite')
        ->call('contextUpdateActivity', $activity->id);

    expect($a->fresh()->activity_id)->toBe($activity->id)->and($b->fresh()->activity_id)->toBe($activity->id)
        ->and($a->fresh()->comments)->toBe('keep');
});

test('a menu pick with a non-activity id is rejected', function () {
    $project = Project::factory()->create();
    $user = timeEntryMenuMember($project, ['view_time_entries', 'edit_time_entries']);
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $entry = TimeEntry::factory()->for($project)->create();
    $before = $entry->activity_id;

    Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->set('selected', [(string) $entry->id])->call('contextUpdateActivity', $priority->id)->assertHasErrors(['bulkActivityId']);
    expect($entry->fresh()->activity_id)->toBe($before);
});

test('the menu carries the delete entry next to the bulk panel\'s own button', function () {
    $project = Project::factory()->create();
    $user = timeEntryMenuMember($project, ['view_time_entries', 'edit_time_entries']);
    $entry = TimeEntry::factory()->for($project)->create();

    $menu = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])->call('openContextMenu', $entry->id);

    expect(substr_count($menu->html(), 'wire:click="applyBulkDelete"'))->toBe(2);
    $menu->assertSee('data-context-menu', false);
});
