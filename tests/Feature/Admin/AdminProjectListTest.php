<?php

use App\Enums\ProjectStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

/**
 * A2-05: 管理 → プロジェクト (Redmine's admin/projects over ProjectAdminQuery).
 */
function adminProjectListManager(): User
{
    $user = User::factory()->create();
    $project = Project::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'edit_project', 'delete_project', 'close_project', 'save_queries']])
    );

    return $user;
}

/**
 * @return array<int, string>
 */
function adminProjectListNames(mixed $component): array
{
    return $component->get('projects')->getCollection()->pluck('name')->sort()->values()->all();
}

test('only an administrator can open the admin project list, and only they get the menu link', function () {
    $manager = adminProjectListManager();
    $admin = User::factory()->admin()->create();

    $this->get(route('admin.projects'))->assertRedirect(route('login'));
    $this->actingAs($manager)->get(route('admin.projects'))->assertForbidden();
    Livewire::actingAs($manager)->test('admin.projects')->assertForbidden();

    $this->actingAs($manager)->get(route('projects.index'))->assertDontSee(route('admin.projects'));
    $this->actingAs($admin)->get(route('admin.projects'))->assertOk()->assertSee(route('admin.projects'));
});

test('it opens filtered on active projects and lists every status once the filter is removed', function () {
    Project::factory()->create(['name' => 'Active one']);
    Project::factory()->create(['name' => 'Closed one', 'status' => ProjectStatus::Closed]);
    Project::factory()->create(['name' => 'Archived one', 'status' => ProjectStatus::Archived, 'is_public' => false]);

    $list = Livewire::actingAs(User::factory()->admin()->create())->test('admin.projects');

    expect(adminProjectListNames($list))->toBe(['Active one']);

    $list->call('removeFilter', 'status')->call('applyFilters');
    expect(adminProjectListNames($list))->toBe(['Active one', 'Archived one', 'Closed one']);

    $list->set('activeFilterKeys', ['status'])
        ->set('filterOperators', ['status' => '='])
        ->set('filterValues', ['status' => ['archived']])
        ->call('applyFilters');
    expect(adminProjectListNames($list))->toBe(['Archived one']);
});

test('columns and sorting come from the project list registry', function () {
    Project::factory()->create(['name' => 'Beta', 'identifier' => 'beta']);
    Project::factory()->create(['name' => 'Alpha', 'identifier' => 'alpha']);

    $list = Livewire::actingAs(User::factory()->admin()->create())->test('admin.projects')
        ->set('columns', ['name', 'identifier', 'status', 'bogus'])
        ->call('sortBy', 'name');

    expect($list->get('visibleColumns'))->toBe(['name', 'identifier', 'status'])
        ->and($list->get('projects')->getCollection()->pluck('name')->all())->toBe(['Alpha', 'Beta']);
    $list->assertSee('アクティブ');
});

test('an administrator archives and unarchives one project from the menu', function () {
    $project = Project::factory()->create();
    $list = Livewire::actingAs(User::factory()->admin()->create())->test('admin.projects');

    $list->call('openContextMenu', $project->id)->call('archive', $project->id);
    expect($project->fresh()->status)->toBe(ProjectStatus::Archived);

    $list->call('unarchive', $project->id);
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('bulk delete needs sudo mode and "はい" typed, then removes the selection with its subprojects only', function () {
    $parent = Project::factory()->create(['name' => 'Parent']);
    $child = Project::factory()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    $grandchild = Project::factory()->create(['name' => 'Grandchild', 'parent_id' => $child->id]);
    $other = Project::factory()->create(['name' => 'Other']);
    $kept = Project::factory()->create(['name' => 'Kept']);

    $list = Livewire::actingAs(User::factory()->admin()->create())->test('admin.projects')
        ->set('selected', [(string) $parent->id, (string) $child->id, (string) $other->id])
        ->call('startBulkDelete')
        ->assertSee('次のサブプロジェクトを含む: Child, Grandchild');

    // No recent password confirmation: sent to confirm, nothing deleted.
    $list->set('bulkDeleteConfirmation', 'はい')->call('bulkDelete')->assertRedirect(route('password.confirm'));
    expect(Project::query()->count())->toBe(5);

    session(['auth.password_confirmed_at' => now()->unix()]);

    $list->set('bulkDeleteConfirmation', 'yes')->call('bulkDelete')->assertHasErrors('bulkDeleteConfirmation');
    expect(Project::query()->count())->toBe(5);

    $list->set('bulkDeleteConfirmation', 'はい')->call('bulkDelete')->assertHasNoErrors();

    expect(Project::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Project::query()->find($grandchild->id))->toBeNull();
});

test('a non-administrator cannot run the list actions', function () {
    $manager = adminProjectListManager();
    $project = Project::factory()->create();

    Livewire::actingAs($manager)->test('admin.projects')->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});
