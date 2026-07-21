<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Livewire;

test('searching by name finds a matching subproject', function () {
    $parent = Project::factory()->create(['name' => 'Parent Project']);
    $child = Project::factory()->create(['name' => 'Findable Child', 'parent_id' => $parent->id]);
    $unrelated = Project::factory()->create(['name' => 'Something Else']);
    $user = User::factory()->create();

    $names = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('search', 'Findable')
        ->get('projects')
        ->pluck('name');

    expect($names)->toContain('Findable Child')
        ->not->toContain('Parent Project')
        ->not->toContain('Something Else');
});

test('searching by identifier also matches', function () {
    $project = Project::factory()->create(['name' => 'Alpha', 'identifier' => 'unique-identifier']);
    Project::factory()->create(['name' => 'Beta']);
    $user = User::factory()->create();

    $names = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('search', 'unique-identifier')
        ->get('projects')
        ->pluck('name');

    expect($names)->toContain('Alpha');
});

test('the status filter narrows the list to matching projects', function () {
    $active = Project::factory()->create(['name' => 'Active One']);
    $closed = Project::factory()->create(['name' => 'Closed One', 'status' => ProjectStatus::Closed]);
    $admin = User::factory()->admin()->create();

    $names = Livewire::actingAs($admin)
        ->test('projects.index')
        ->set('statusFilter', 'closed')
        ->get('projects')
        ->pluck('name');

    expect($names)->toContain('Closed One')->not->toContain('Active One');
});

test('the project list paginates once a filter is active', function () {
    Project::factory()->count(30)->create();
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)
        ->test('projects.index')
        ->set('statusFilter', 'active');

    expect($component->get('projects'))->toBeInstanceOf(Paginator::class)
        ->and($component->get('projects')->count())->toBe(25);
});

test('without any filter the list is not paginated and shows only root projects', function () {
    $parent = Project::factory()->create(['name' => 'Root']);
    Project::factory()->create(['name' => 'Nested Child', 'parent_id' => $parent->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');

    expect($projects)->toBeInstanceOf(Collection::class);
    expect($projects->pluck('name'))->toContain('Root')->not->toContain('Nested Child');
});
