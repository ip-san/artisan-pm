<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create an issue status', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('issue-statuses.form')
        ->set('name', 'In Review')
        ->set('is_closed', false)
        ->call('save')
        ->assertRedirect(route('issue-statuses.index'));

    $status = IssueStatus::where('name', 'In Review')->firstOrFail();

    expect($status->is_closed)->toBeFalse();
});

test('a non-admin cannot access issue status administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('issue-statuses.index')->assertForbidden();
    Livewire::actingAs($user)->test('issue-statuses.form')->assertForbidden();
});

test('an admin can delete an unused status', function () {
    $admin = User::factory()->admin()->create();
    $status = IssueStatus::factory()->create();

    Livewire::actingAs($admin)->test('issue-statuses.index')->call('delete', $status->id);

    expect(IssueStatus::find($status->id))->toBeNull();
});

test('a status in use by an issue cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $status = IssueStatus::factory()->create();
    $project = Project::factory()->create();
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => $status->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)->test('issue-statuses.index')->call('delete', $status->id);

    expect(IssueStatus::find($status->id))->not->toBeNull();
});
