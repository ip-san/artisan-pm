<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 * @return array{project: Project, user: User, issue: Issue, other: Issue}
 */
function contextMenuSetup(array $permissions = ['view_project', 'view_issues', 'edit_issues', 'delete_issues']): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    $make = fn () => Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    return ['project' => $project, 'user' => $user, 'issue' => $make(), 'other' => $make()];
}

test('right-clicking an unselected row makes it the only selection', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue, 'other' => $other] = contextMenuSetup();

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $other->id])->call('openContextMenu', $issue->id);

    expect($list->get('selected'))->toBe([(string) $issue->id]);
});

test('right-clicking a selected row keeps the whole selection', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue, 'other' => $other] = contextMenuSetup();

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id, (string) $other->id])->call('openContextMenu', $issue->id);

    expect($list->get('selected'))->toBe([(string) $issue->id, (string) $other->id]);
});

test('the menu is not offered without edit_issues and its actions are refused', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup(['view_project', 'view_issues']);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project]);
    $list->assertDontSee('data-context-menu', false);
    $list->call('openContextMenu', $issue->id)->assertForbidden();
});

test('an issue from another project cannot be selected through the menu', function () {
    ['project' => $project, 'user' => $user] = contextMenuSetup();
    $foreign = Issue::factory()->for(Project::factory()->create())->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->call('openContextMenu', $foreign->id)->assertNotFound();
});

test('a menu pick changes every selected issue and journals it', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue, 'other' => $other] = contextMenuSetup();
    $priority = Enumeration::factory()->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $issue->id, (string) $other->id])
        ->call('contextUpdate', 'priority_id', (string) $priority->id)
        ->assertHasNoErrors();

    expect($issue->fresh()->priority_id)->toBe($priority->id)->and($other->fresh()->priority_id)->toBe($priority->id);
    expect($issue->journals()->count())->toBe(1);
});

test('a menu pick ignores half-filled bulk form values', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();
    $category = IssueCategory::factory()->for($project)->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $issue->id])
        ->set('bulkDueDate', '2030-01-01')
        ->call('contextUpdate', 'category_id', (string) $category->id);

    expect($issue->fresh()->category_id)->toBe($category->id)->and($issue->fresh()->due_date)->toBeNull();
});

test('assigning to me, another member, and nobody all work', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id]);
    $list->call('contextUpdate', 'assigned_to_id', 'me');
    expect($issue->fresh()->assigned_to_id)->toBe($user->id);

    $list->set('selected', [(string) $issue->id])->call('contextUpdate', 'assigned_to_id', 'none');
    expect($issue->fresh()->assigned_to_id)->toBeNull();
});

test('none clears a version and a category', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();
    $issue->update(['fixed_version_id' => Version::factory()->for($project)->create()->id, 'category_id' => IssueCategory::factory()->for($project)->create()->id]);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id]);
    $list->call('contextUpdate', 'fixed_version_id', 'none');
    $list->set('selected', [(string) $issue->id])->call('contextUpdate', 'category_id', 'none');

    expect($issue->fresh()->fixed_version_id)->toBeNull()->and($issue->fresh()->category_id)->toBeNull();
});

test('none is refused for fields that cannot be blank, and unknown fields are refused', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id])->call('contextUpdate', 'priority_id', 'none')->assertStatus(422);
    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id])->call('contextUpdate', 'subject', 'x')->assertForbidden();
});

test('a menu pick from a foreign project is rejected by the bulk validation', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();
    $foreignVersion = Version::factory()->for(Project::factory()->create())->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id])->call('contextUpdate', 'fixed_version_id', (string) $foreignVersion->id)->assertHasErrors(['bulkFixedVersionId']);
    expect($issue->fresh()->fixed_version_id)->toBeNull();
});

test('the rendered menu lists the pick-lists and only offers delete to those who may', function () {
    ['project' => $project, 'user' => $user, 'issue' => $issue] = contextMenuSetup();
    Version::factory()->for($project)->create(['name' => 'Release 9']);

    $list = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->call('openContextMenu', $issue->id);
    $list->assertSee('data-context-menu', false)->assertSee('Release 9')->assertSee('削除');

    $noDelete = contextMenuSetup(['view_project', 'view_issues', 'edit_issues']);
    $menu = Livewire::actingAs($noDelete['user'])->test('issues.index', ['project' => $noDelete['project']])->call('openContextMenu', $noDelete['issue']->id);
    expect(substr_count($menu->html(), 'wire:click="applyBulkDelete"'))->toBe(0);
});
