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

test('without any filter each project exposes its display level, used for indentation', function () {
    $root = Project::factory()->create(['name' => 'Root']);
    $child = Project::factory()->create(['name' => 'Child', 'parent_id' => $root->id]);
    Project::factory()->create(['name' => 'Grandchild', 'parent_id' => $child->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');
    $levelByName = $projects->pluck('display_level', 'name');

    expect($projects->pluck('name')->all())->toBe(['Root', 'Child', 'Grandchild'])
        ->and($levelByName['Root'])->toBe(0)
        ->and($levelByName['Child'])->toBe(1)
        ->and($levelByName['Grandchild'])->toBe(2);
});

test('a filtered project list keeps tree order and indents matches under matching ancestors', function () {
    $root = Project::factory()->create(['name' => 'Alpha Root']);
    Project::factory()->create(['name' => 'Alpha Child', 'parent_id' => $root->id]);
    Project::factory()->create(['name' => 'Other']);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('search', 'Alpha')
        ->get('projects');

    expect($projects->pluck('name')->all())->toBe(['Alpha Root', 'Alpha Child'])
        ->and($projects->pluck('display_level')->all())->toBe([0, 1]);
});

test('a match whose parent is filtered out starts a new top-level entry', function () {
    $root = Project::factory()->create(['name' => 'Umbrella']);
    Project::factory()->create(['name' => 'Alpha Child', 'parent_id' => $root->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('search', 'Alpha')
        ->get('projects');

    expect($projects->pluck('name')->all())->toBe(['Alpha Child'])
        ->and($projects->first()->display_level)->toBe(0);
});

test('a project whose parent the viewer cannot see is not indented under a hidden parent', function () {
    $hiddenRoot = Project::factory()->private()->create(['name' => 'Secret Root']);
    $visibleChild = Project::factory()->create(['name' => 'Public Child', 'parent_id' => $hiddenRoot->id]);
    $visibleGrandchild = Project::factory()->create(['name' => 'Public Grandchild', 'parent_id' => $visibleChild->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');

    expect($projects->pluck('name')->all())->toBe(['Public Child', 'Public Grandchild'])
        ->and($projects->pluck('display_level')->all())->toBe([0, 1]);
});

test('sibling subtrees do not inherit each other\'s level', function () {
    $a = Project::factory()->create(['name' => 'A']);
    Project::factory()->create(['name' => 'A1', 'parent_id' => $a->id]);
    $b = Project::factory()->create(['name' => 'B']);
    Project::factory()->create(['name' => 'B1', 'parent_id' => $b->id]);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->get('projects');

    expect($projects->pluck('name')->all())->toBe(['A', 'A1', 'B', 'B1'])
        ->and($projects->pluck('display_level')->all())->toBe([0, 1, 0, 1]);
});
