<?php

use App\Enums\ProjectStatus;
use App\Enums\VersionSharing;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use App\Support\Authorization\AuthorizationService;
use Livewire\Livewire;

test('a member with close_project can close and reopen a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['close_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('closeProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Closed);

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('reopenProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a member without close_project cannot close a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->call('closeProject')
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('only an admin can archive or unarchive a project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['close_project', 'edit_project']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Livewire::actingAs($user)
        ->test('projects.show', ['project' => $project])
        ->call('archiveProject')
        ->assertForbidden();

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('projects.show', ['project' => $project])->call('archiveProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);

    Livewire::actingAs($admin)->test('projects.show', ['project' => $project])->call('unarchiveProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a non-active project shows a status badge on its show page', function () {
    $project = Project::factory()->create();
    $project->status = ProjectStatus::Closed;
    $project->save();

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('projects.show', ['project' => $project])
        ->assertSee('クローズ');
});

function closedProjectMember(array $permissions): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));
    $project->status = ProjectStatus::Closed;
    $project->save();

    return [$project->fresh(), $user];
}

test('a closed project keeps the read-only administration permissions Redmine flags as read', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(AuthorizationService::class)->can($user, $permission, $project))->toBeTrue();
})->with(['close_project', 'delete_project']);

test('a closed project blocks manage_members, add_subprojects, manage_public_queries, edit_project and select_project_modules', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(AuthorizationService::class)->can($user, $permission, $project))->toBeFalse();

    $project->status = ProjectStatus::Active;
    $project->save();

    expect(app(AuthorizationService::class)->can($user, $permission, $project->fresh()))->toBeTrue();
})->with(['manage_members', 'add_subprojects', 'manage_public_queries', 'edit_project', 'select_project_modules']);

test('a closed project still lets its members read and reopen it but not manage members', function () {
    [$project, $user] = closedProjectMember(['view_project', 'close_project', 'manage_members']);

    Livewire::actingAs($user)->test('projects.members', ['project' => $project])->assertForbidden();

    Livewire::actingAs($user)->test('projects.show', ['project' => $project])->call('reopenProject');
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);

    Livewire::actingAs($user)->test('projects.members', ['project' => $project->fresh()])->assertOk();
});

test('a closed project blocks a member from editing or deleting their own notes and messages', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(AuthorizationService::class)->can($user, $permission, $project))->toBeFalse();
})->with(['edit_own_issue_notes', 'delete_own_messages']);

test('a closed project still blocks the write permissions of its modules', function (string $permission) {
    [$project, $user] = closedProjectMember([$permission]);

    expect(app(AuthorizationService::class)->can($user, $permission, $project))->toBeFalse();
})->with(['add_issues', 'edit_issues', 'log_time', 'edit_wiki_pages', 'add_messages', 'manage_versions']);

/**
 * @return array{0: Project, 1: Project, 2: Project}
 */
function projectTreeForStatusCascade(): array
{
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $grandchild = Project::factory()->create(['parent_id' => $child->id]);

    return [$parent->fresh(), $child->fresh(), $grandchild->fresh()];
}

test('closing a project closes its active subprojects and reopening reopens them, leaving archived ones alone', function () {
    [$parent, $child, $grandchild] = projectTreeForStatusCascade();
    $archivedChild = Project::factory()->create(['parent_id' => $parent->id, 'status' => ProjectStatus::Archived]);
    $sibling = Project::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.show', ['project' => $parent])->call('closeProject');

    expect($parent->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($archivedChild->fresh()->status)->toBe(ProjectStatus::Archived)
        ->and($sibling->fresh()->status)->toBe(ProjectStatus::Active);

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.show', ['project' => $parent->fresh()])->call('reopenProject');

    expect($parent->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($archivedChild->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('closing a subproject leaves its parent open', function () {
    [$parent, $child, $grandchild] = projectTreeForStatusCascade();

    $child->close();

    expect($parent->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Closed);
});

test('a member with close_project on a subproject only closes that subtree, not the parent', function () {
    [$parent, $child] = projectTreeForStatusCascade();
    $user = User::factory()->create();
    Member::factory()->for($child)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['close_project']]));

    Livewire::actingAs($user)->test('projects.show', ['project' => $parent])->call('closeProject')->assertForbidden();
    expect($parent->fresh()->status)->toBe(ProjectStatus::Active);

    Livewire::actingAs($user)->test('projects.show', ['project' => $child])->call('closeProject');
    expect($parent->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Closed);
});

test('archiving a project archives its whole subtree', function () {
    [$parent, $child, $grandchild] = projectTreeForStatusCascade();

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.show', ['project' => $parent])->call('archiveProject')->assertHasNoErrors();

    expect($parent->fresh()->status)->toBe(ProjectStatus::Archived)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Archived)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('archiving is refused while an issue outside the subtree targets one of its versions', function () {
    [$parent, $child] = projectTreeForStatusCascade();
    $version = Version::factory()->for($child)->create(['sharing' => VersionSharing::System]);
    $outside = Project::factory()->create();
    Issue::factory()->for($outside)->create(['fixed_version_id' => $version->id]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('projects.show', ['project' => $parent])
        ->call('archiveProject')
        ->assertHasErrors('archive')
        ->assertSee('このプロジェクトはアーカイブできません');

    expect($parent->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Active);
});

test('an issue inside the subtree targeting a subtree version does not block archiving', function () {
    [$parent, $child] = projectTreeForStatusCascade();
    $version = Version::factory()->for($child)->create(['sharing' => VersionSharing::Hierarchy]);
    Issue::factory()->for($parent)->create(['fixed_version_id' => $version->id]);

    expect($parent->archive())->toBeTrue()
        ->and($child->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('unarchiving a subproject unarchives its archived ancestors but not its subprojects', function () {
    [$parent, $child, $grandchild] = projectTreeForStatusCascade();
    $parent->archive();

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.show', ['project' => $child->fresh()])->call('unarchiveProject');

    expect($parent->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('unarchiving under a closed ancestor brings the project back closed', function () {
    [$parent, $child, $grandchild] = projectTreeForStatusCascade();
    $parent->close();
    $child->fresh()->archive();

    $grandchild->fresh()->unarchive();

    expect($parent->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($child->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($grandchild->fresh()->status)->toBe(ProjectStatus::Closed);
});
