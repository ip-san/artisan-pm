<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function multiSortMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a secondary sort key breaks ties left by the primary key', function () {
    $project = Project::factory()->create();
    $user = multiSortMember($project);
    $trackerA = Tracker::factory()->create();
    $trackerB = Tracker::factory()->create();

    $zebra = Issue::factory()->for($project)->create(['tracker_id' => $trackerA->id, 'subject' => 'Zebra']);
    $apple = Issue::factory()->for($project)->create(['tracker_id' => $trackerA->id, 'subject' => 'Apple']);
    $mango = Issue::factory()->for($project)->create(['tracker_id' => $trackerB->id, 'subject' => 'Mango']);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('sortKey', 'tracker_id')
        ->set('sortDirection', 'asc')
        ->set('sortKey2', 'subject')
        ->set('sortDirection2', 'asc');

    $ids = $component->get('issues')->pluck('id')->all();

    expect($ids)->toBe([$apple->id, $zebra->id, $mango->id]);
});

test('without a secondary key, tie order follows the database default', function () {
    $project = Project::factory()->create();
    $user = multiSortMember($project);
    $tracker = Tracker::factory()->create();

    $first = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $second = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('sortKey', 'tracker_id');

    expect($component->get('issues')->pluck('id')->all())->toContain($first->id)->toContain($second->id);
});

test('saving a query persists all 3 sort levels and loading restores them', function () {
    $project = Project::factory()->create();
    $user = multiSortMember($project);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('sortKey', 'tracker_id')
        ->set('sortDirection', 'asc')
        ->set('sortKey2', 'priority_id')
        ->set('sortDirection2', 'desc')
        ->set('sortKey3', 'subject')
        ->set('sortDirection3', 'asc')
        ->set('newQueryName', 'Three-level sort')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Three-level sort')->firstOrFail();

    expect($saved->sort_criteria)->toBe([
        ['tracker_id', 'asc'],
        ['priority_id', 'desc'],
        ['subject', 'asc'],
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('loadQuery', $saved->id);

    expect($component->get('sortKey'))->toBe('tracker_id')
        ->and($component->get('sortKey2'))->toBe('priority_id')
        ->and($component->get('sortDirection2'))->toBe('desc')
        ->and($component->get('sortKey3'))->toBe('subject');
});

test('loading a query with fewer sort levels clears any previously set secondary/tertiary keys', function () {
    $project = Project::factory()->create();
    $user = multiSortMember($project);

    $saved = SavedQuery::create([
        'name' => 'Single level',
        'type' => 'issue',
        'user_id' => $user->id,
        'project_id' => $project->id,
        'is_public' => false,
        'filters' => [],
        'column_names' => ['subject'],
        'sort_criteria' => [['subject', 'asc']],
        'group_by' => null,
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('sortKey2', 'priority_id')
        ->call('loadQuery', $saved->id);

    expect($component->get('sortKey'))->toBe('subject')
        ->and($component->get('sortKey2'))->toBeNull();
});
