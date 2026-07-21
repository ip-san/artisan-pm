<?php

use App\Enums\EnumerationType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create an enumeration of a given type', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::IssuePriority])
        ->set('name', 'Urgent')
        ->call('save')
        ->assertRedirect(route('enumerations.index', EnumerationType::IssuePriority->value));

    $enumeration = Enumeration::where('name', 'Urgent')->firstOrFail();

    expect($enumeration->type)->toBe(EnumerationType::IssuePriority);
});

test('a non-admin cannot access enumeration administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('enumerations.index', ['type' => EnumerationType::IssuePriority])->assertForbidden();
    Livewire::actingAs($user)->test('enumerations.form', ['type' => EnumerationType::IssuePriority])->assertForbidden();
});

test('the index only lists enumerations of the requested type', function () {
    $admin = User::factory()->admin()->create();
    Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'Priority A']);
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'name' => 'Activity A']);

    $component = Livewire::actingAs($admin)->test('enumerations.index', ['type' => EnumerationType::IssuePriority]);

    expect($component->get('enumerations')->pluck('name'))->toContain('Priority A')->not->toContain('Activity A');
});

test('marking an enumeration as default clears the previous default of the same type', function () {
    $admin = User::factory()->admin()->create();
    $first = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'is_default' => true]);
    $second = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'is_default' => false]);

    Livewire::actingAs($admin)
        ->test('enumerations.index', ['type' => EnumerationType::IssuePriority])
        ->call('makeDefault', $second->id);

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

test('setting is_default on the create form also clears the previous default', function () {
    $admin = User::factory()->admin()->create();
    $existing = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'is_default' => true]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::IssuePriority])
        ->set('name', 'New Default')
        ->set('is_default', true)
        ->call('save');

    expect($existing->fresh()->is_default)->toBeFalse()
        ->and(Enumeration::where('name', 'New Default')->firstOrFail()->is_default)->toBeTrue();
});

test('an issue priority in use by an issue cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);
    $project = Project::factory()->create();
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => $priority->id,
        'author_id' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('enumerations.index', ['type' => EnumerationType::IssuePriority])
        ->call('delete', $priority->id);

    expect(Enumeration::find($priority->id))->not->toBeNull();
});

test('a time entry activity in use cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $project = Project::factory()->create();
    TimeEntry::factory()->for($project)->for($admin, 'user')->create(['activity_id' => $activity->id]);

    Livewire::actingAs($admin)
        ->test('enumerations.index', ['type' => EnumerationType::TimeEntryActivity])
        ->call('delete', $activity->id);

    expect(Enumeration::find($activity->id))->not->toBeNull();
});

test('an unused enumeration can be deleted', function () {
    $admin = User::factory()->admin()->create();
    $enumeration = Enumeration::factory()->create(['type' => EnumerationType::DocumentCategory->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.index', ['type' => EnumerationType::DocumentCategory])
        ->call('delete', $enumeration->id);

    expect(Enumeration::find($enumeration->id))->toBeNull();
});

test('the enumerations.index route resolves the type segment from a real request', function () {
    // Livewire::test() sets mount() parameters directly from whatever PHP
    // value is passed in, bypassing Laravel's own implicit enum route
    // binding entirely — every other test in this file passes the enum
    // case itself for that reason. This one goes through an actual HTTP
    // request instead, to confirm the real URL (a plain string segment
    // like "issue_priority") genuinely resolves to the enum in production,
    // not just in the test harness's shortcut.
    $admin = User::factory()->admin()->create();
    Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value, 'name' => 'Real Request Priority']);

    $this->actingAs($admin)
        ->get(route('enumerations.index', EnumerationType::IssuePriority->value))
        ->assertOk()
        ->assertSee('Real Request Priority');
});

test('editing an enumeration via a mismatched type route is not found', function () {
    $admin = User::factory()->admin()->create();
    $priority = Enumeration::factory()->create(['type' => EnumerationType::IssuePriority->value]);

    Livewire::actingAs($admin)
        ->test('enumerations.form', ['type' => EnumerationType::DocumentCategory, 'enumeration' => $priority])
        ->assertStatus(404);
});
