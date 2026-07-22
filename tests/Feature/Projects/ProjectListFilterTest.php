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

test('without any filter the list is not paginated and shows both root and subprojects in tree order', function () {
    $parent = Project::factory()->create(['name' => 'Root']);
    Project::factory()->create(['name' => 'Nested Child', 'parent_id' => $parent->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');

    expect($projects)->toBeInstanceOf(Collection::class);
    expect($projects->pluck('name')->all())->toBe(['Root', 'Nested Child']);
});

test('without any filter each project exposes its nested-set depth, used for indentation', function () {
    $root = Project::factory()->create(['name' => 'Root']);
    $child = Project::factory()->create(['name' => 'Child', 'parent_id' => $root->id]);
    Project::factory()->create(['name' => 'Grandchild', 'parent_id' => $child->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');
    $depthByName = $projects->pluck('depth', 'name');

    expect($depthByName['Root'])->toBe(0)
        ->and($depthByName['Child'])->toBe(1)
        ->and($depthByName['Grandchild'])->toBe(2);
});

test('a filtered project list has no depth attribute and stays flat/alphabetical', function () {
    $root = Project::factory()->create(['name' => 'Alpha Root']);
    Project::factory()->create(['name' => 'Alpha Child', 'parent_id' => $root->id]);
    $user = User::factory()->create();

    $names = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('search', 'Alpha')
        ->get('projects')
        ->pluck('name');

    expect($names->all())->toBe(['Alpha Child', 'Alpha Root']);
});
