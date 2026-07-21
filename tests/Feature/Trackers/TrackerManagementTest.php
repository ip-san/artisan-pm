<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create a tracker', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('trackers.form')
        ->set('name', 'Support Request')
        ->set('description', 'Customer support tickets')
        ->call('save')
        ->assertRedirect(route('trackers.index'));

    $tracker = Tracker::where('name', 'Support Request')->firstOrFail();

    expect($tracker->description)->toBe('Customer support tickets');
});

test('a non-admin cannot access tracker administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('trackers.index')->assertForbidden();
    Livewire::actingAs($user)->test('trackers.form')->assertForbidden();
});

test('an admin can delete an unused tracker', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('trackers.index')->call('delete', $tracker->id);

    expect(Tracker::find($tracker->id))->toBeNull();
});

test('a tracker in use by an issue cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)->test('trackers.index')->call('delete', $tracker->id);

    expect(Tracker::find($tracker->id))->not->toBeNull();
});
