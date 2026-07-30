<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Livewire\Livewire;

function withRecentlyConfirmedPasswordForProjectDeletionTest(): void
{
    session(['auth.password_confirmed_at' => now()->unix()]);
}

test('an admin can delete a leaf project by typing its identifier', function () {
    $project = Project::factory()->create(['identifier' => 'to-delete']);
    $admin = User::factory()->admin()->create();
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'to-delete')
        ->call('deleteProject')
        ->assertRedirect(route('projects.index'));

    expect(Project::find($project->id))->toBeNull();
});

test('deleting a project cascades to its issues, versions, and members', function () {
    $project = Project::factory()->create(['identifier' => 'cascade-test']);
    $issue = Issue::factory()->for($project)->create();
    $version = Version::factory()->for($project)->create();
    $member = Member::factory()->for($project)->create();
    $admin = User::factory()->admin()->create();
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'cascade-test')
        ->call('deleteProject');

    expect(Issue::find($issue->id))->toBeNull()
        ->and(Version::find($version->id))->toBeNull()
        ->and(Member::find($member->id))->toBeNull();
});

test('deleting a parent project also deletes its subprojects (admin only)', function () {
    $parent = Project::factory()->create(['identifier' => 'parent-project']);
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $admin = User::factory()->admin()->create();
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $parent])
        ->set('deleteConfirmationInput', 'parent-project')
        ->call('deleteProject');

    expect(Project::find($parent->id))->toBeNull()
        ->and(Project::find($child->id))->toBeNull();
});

test('deleting a project cascades through the whole subtree, not just direct children', function () {
    // parent_id's own FK is nullOnDelete() — a single-level test could
    // pass purely because the child got orphaned to top-level by that FK,
    // while kalnoy/nestedset's own descendants()->delete() never ran at
    // all. A grandchild only survives that orphaning path (parent_id
    // still points at the now-deleted child, which nullOnDelete() does
    // NOT reach transitively) — so this level proves the nested-set
    // cascade is real, not a coincidence of two independent mechanisms
    // producing the same top-level result.
    $root = Project::factory()->create(['identifier' => 'root-project']);
    $child = Project::factory()->create(['parent_id' => $root->id]);
    $grandchild = Project::factory()->create(['parent_id' => $child->id]);

    // Confirms the factory-built tree is genuinely nested (kalnoy computes
    // _lft/_rgt from parent_id on create, not just via appendToNode())
    // before trusting that descendants() cascading proves anything.
    expect($root->fresh()->descendants()->count())->toBe(2);

    $admin = User::factory()->admin()->create();
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $root])
        ->set('deleteConfirmationInput', 'root-project')
        ->call('deleteProject');

    expect(Project::find($root->id))->toBeNull()
        ->and(Project::find($child->id))->toBeNull()
        ->and(Project::find($grandchild->id))->toBeNull();
});

test('a non-admin with delete_project may delete a leaf project they are a member of', function () {
    $project = Project::factory()->create(['identifier' => 'member-owned']);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['delete_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'member-owned')
        ->call('deleteProject')
        ->assertRedirect(route('projects.index'));

    expect(Project::find($project->id))->toBeNull();
});

test('a non-admin with delete_project cannot delete a project that has subprojects', function () {
    $parent = Project::factory()->create(['identifier' => 'has-children']);
    Project::factory()->create(['parent_id' => $parent->id]);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['delete_project']]);
    Member::factory()->for($parent)->for($user)->create()->roles()->attach($role);
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $parent])
        ->set('deleteConfirmationInput', 'has-children')
        ->call('deleteProject')
        ->assertForbidden();

    expect(Project::find($parent->id))->not->toBeNull();
});

test('a member without delete_project cannot delete a project', function () {
    $project = Project::factory()->create(['identifier' => 'protected']);
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'protected')
        ->call('deleteProject')
        ->assertForbidden();

    expect(Project::find($project->id))->not->toBeNull();
});

test('typing the wrong identifier does not delete the project', function () {
    $project = Project::factory()->create(['identifier' => 'careful']);
    $admin = User::factory()->admin()->create();
    withRecentlyConfirmedPasswordForProjectDeletionTest();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'wrong-identifier')
        ->call('deleteProject')
        ->assertHasErrors(['deleteConfirmationInput']);

    expect(Project::find($project->id))->not->toBeNull();
});

test('deleting a project without a recent password confirmation redirects to confirm instead', function () {
    $project = Project::factory()->create(['identifier' => 'sudo-guard']);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->set('deleteConfirmationInput', 'sudo-guard')
        ->call('deleteProject')
        ->assertRedirect(route('password.confirm'));

    expect(Project::find($project->id))->not->toBeNull();
});

test('the delete button is not shown to a non-admin member without delete_project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->assertDontSee('プロジェクトの削除');
});
