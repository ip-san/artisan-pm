<?php

use App\Enums\VersionSharing;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

/**
 * Builds a small project tree:
 *
 *   root
 *   ├── child
 *   │   └── grandchild
 *   └── sibling
 *   (unrelated is a separate root)
 *
 * @return array<string, Project>
 */
function versionSharingTree(): array
{
    $root = Project::factory()->create(['name' => 'Root']);
    $child = Project::factory()->create(['name' => 'Child', 'parent_id' => $root->id]);
    $grandchild = Project::factory()->create(['name' => 'Grandchild', 'parent_id' => $child->id]);
    $sibling = Project::factory()->create(['name' => 'Sibling', 'parent_id' => $root->id]);
    $unrelated = Project::factory()->create(['name' => 'Unrelated']);

    return [
        'root' => $root->fresh(),
        'child' => $child->fresh(),
        'grandchild' => $grandchild->fresh(),
        'sibling' => $sibling->fresh(),
        'unrelated' => $unrelated->fresh(),
    ];
}

test('a project always sees its own versions regardless of sharing', function () {
    $tree = versionSharingTree();
    $own = Version::factory()->for($tree['child'])->create(['sharing' => VersionSharing::None->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->toContain($own->id);
});

test('a descendants-shared version on an ancestor reaches its descendants but not outside', function () {
    $tree = versionSharingTree();
    $shared = Version::factory()->for($tree['root'])->create(['sharing' => VersionSharing::Descendants->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['grandchild']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['unrelated']->sharedVersions()->pluck('id'))->not->toContain($shared->id);
});

test('a plain (none) version on an ancestor does not reach descendants', function () {
    $tree = versionSharingTree();
    $private = Version::factory()->for($tree['root'])->create(['sharing' => VersionSharing::None->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->not->toContain($private->id);
});

test('a hierarchy-shared version reaches both ancestors and descendants', function () {
    $tree = versionSharingTree();
    // Shared from the grandchild (deepest) — hierarchy makes it visible up the chain.
    $shared = Version::factory()->for($tree['grandchild'])->create(['sharing' => VersionSharing::Hierarchy->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['root']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['sibling']->sharedVersions()->pluck('id'))->not->toContain($shared->id);
});

test('a descendants-shared version on a descendant does not reach its ancestors', function () {
    $tree = versionSharingTree();
    $shared = Version::factory()->for($tree['grandchild'])->create(['sharing' => VersionSharing::Descendants->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->not->toContain($shared->id);
});

test('a tree-shared version reaches every project in the same tree but not another tree', function () {
    $tree = versionSharingTree();
    $shared = Version::factory()->for($tree['sibling'])->create(['sharing' => VersionSharing::Tree->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['grandchild']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['unrelated']->sharedVersions()->pluck('id'))->not->toContain($shared->id);
});

test('a system-shared version reaches every project', function () {
    $tree = versionSharingTree();
    $shared = Version::factory()->for($tree['unrelated'])->create(['sharing' => VersionSharing::System->value]);

    expect($tree['child']->sharedVersions()->pluck('id'))->toContain($shared->id)
        ->and($tree['root']->sharedVersions()->pluck('id'))->toContain($shared->id);
});

test('the issue form offers a version shared from a parent project', function () {
    $tree = versionSharingTree();
    $sharedVersion = Version::factory()->for($tree['root'])->create(['name' => 'Shared release', 'sharing' => VersionSharing::Descendants->value]);

    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues']]);
    Member::factory()->for($tree['child'])->for($user)->create()->roles()->attach($role);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $tree['child']]);

    expect($component->get('projectVersions')->pluck('id'))->toContain($sharedVersion->id);
});

test('allowedSharings limits hierarchy and tree to root-project version managers', function () {
    $tree = versionSharingTree();
    $version = Version::factory()->for($tree['child'])->create();

    // A member who manages versions only on the child (not the root).
    $childManager = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['manage_versions']]);
    Member::factory()->for($tree['child'])->for($childManager)->create()->roles()->attach($role);

    $allowed = collect($version->allowedSharings($childManager))->map(fn (VersionSharing $s) => $s->value);

    expect($allowed)->toContain(VersionSharing::None->value)
        ->and($allowed)->toContain(VersionSharing::Descendants->value)
        ->and($allowed)->not->toContain(VersionSharing::Hierarchy->value)
        ->and($allowed)->not->toContain(VersionSharing::Tree->value)
        ->and($allowed)->not->toContain(VersionSharing::System->value);
});

test('an admin may set system-wide sharing', function () {
    $tree = versionSharingTree();
    $version = Version::factory()->for($tree['child'])->create();
    $admin = User::factory()->admin()->create();

    $allowed = collect($version->allowedSharings($admin))->map(fn (VersionSharing $s) => $s->value);

    expect($allowed)->toContain(VersionSharing::System->value);
});

test('a root-project version manager can set hierarchy and tree sharing through the form', function () {
    $tree = versionSharingTree();
    $rootManager = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['manage_versions']]);
    Member::factory()->for($tree['root'])->for($rootManager)->create()->roles()->attach($role);

    Livewire::actingAs($rootManager)
        ->test('versions.form', ['project' => $tree['root']])
        ->set('name', 'Tree-wide release')
        ->set('sharing', VersionSharing::Tree->value)
        ->call('save')
        ->assertHasNoErrors();

    $version = Version::where('name', 'Tree-wide release')->firstOrFail();

    expect($version->sharing)->toBe(VersionSharing::Tree);
});

test('a version manager without root access cannot set tree sharing via the form', function () {
    $tree = versionSharingTree();
    $childManager = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['manage_versions']]);
    Member::factory()->for($tree['child'])->for($childManager)->create()->roles()->attach($role);

    Livewire::actingAs($childManager)
        ->test('versions.form', ['project' => $tree['child']])
        ->set('name', 'Attempted tree share')
        ->set('sharing', VersionSharing::Tree->value)
        ->call('save')
        ->assertHasErrors(['sharing']);
});
