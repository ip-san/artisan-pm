<?php

use App\Enums\CustomizableType;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function projectListMember(Project $project, array $permissions, ?User $user = null): User
{
    $user ??= User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('a filter from the query engine narrows the list and makes it flat', function () {
    Project::factory()->create(['name' => 'Alpha', 'identifier' => 'alpha']);
    Project::factory()->create(['name' => 'Beta', 'identifier' => 'beta']);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)
        ->test('projects.index')
        ->set('activeFilterKeys', ['identifier'])
        ->set('filterOperators', ['identifier' => '~'])
        ->set('filterValues', ['identifier' => ['alp']])
        ->call('applyFilters')
        ->get('projects');

    expect($projects)->toBeInstanceOf(Paginator::class)
        ->and(collect($projects->items())->pluck('name')->all())->toBe(['Alpha']);
});

test('the public filter and the parent filter work', function () {
    $parent = Project::factory()->create(['name' => 'Parent']);
    Project::factory()->create(['name' => 'Child', 'parent_id' => $parent->id]);
    Project::factory()->private()->create(['name' => 'Hidden Away']);
    $admin = User::factory()->admin()->create();

    $children = Livewire::actingAs($admin)->test('projects.index')
        ->set('activeFilterKeys', ['parent_id'])
        ->set('filterOperators', ['parent_id' => '='])
        ->set('filterValues', ['parent_id' => [(string) $parent->id]])
        ->get('projects');

    $private = Livewire::actingAs($admin)->test('projects.index')
        ->set('activeFilterKeys', ['is_public'])
        ->set('filterOperators', ['is_public' => '='])
        ->set('filterValues', ['is_public' => ['0']])
        ->get('projects');

    expect(collect($children->items())->pluck('name')->all())->toBe(['Child'])
        ->and(collect($private->items())->pluck('name')->all())->toBe(['Hidden Away']);
});

test('a project custom field can be used as a filter and as a column', function () {
    $field = CustomField::factory()->create(['name' => 'Budget code', 'customized_type' => CustomizableType::Project->value]);
    $match = Project::factory()->create(['name' => 'Funded']);
    $match->setCustomFieldValues([$field->id => 'BC-42']);
    Project::factory()->create(['name' => 'Unfunded']);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', "cf_{$field->id}"])
        ->set('activeFilterKeys', ["cf_{$field->id}"])
        ->set('filterOperators', ["cf_{$field->id}" => '='])
        ->set('filterValues', ["cf_{$field->id}" => ['BC-42']]);

    expect(collect($component->get('projects')->items())->pluck('name')->all())->toBe(['Funded']);
    $component->assertSee('Budget code')->assertSee('BC-42');
});

test('a role-restricted project custom field is offered to neither the filters nor the columns of a non-admin', function () {
    $field = CustomField::factory()->create(['name' => 'Secret rating', 'customized_type' => CustomizableType::Project->value]);
    $field->roles()->attach(Role::factory()->create());
    Project::factory()->create()->setCustomFieldValues([$field->id => 'Classified-777']);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', "cf_{$field->id}"])
        ->set('activeFilterKeys', ["cf_{$field->id}"])
        ->set('filterOperators', ["cf_{$field->id}" => '='])
        ->set('filterValues', ["cf_{$field->id}" => ['nothing']]);

    expect($component->get('engine')->field("cf_{$field->id}"))->toBeNull()
        ->and($component->get('visibleColumns'))->toBe(['name']);
    $component->assertDontSee('Secret rating')->assertDontSee('Classified-777');
});

test('sorting by a column makes the list flat and ordered by that column', function () {
    $root = Project::factory()->create(['name' => 'Zulu']);
    Project::factory()->create(['name' => 'Alpha', 'parent_id' => $root->id]);
    Project::factory()->create(['name' => 'Mike']);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('projects.index')->call('sortBy', 'name');

    expect($component->get('projects'))->toBeInstanceOf(Paginator::class)
        ->and(collect($component->get('projects')->items())->pluck('name')->all())->toBe(['Alpha', 'Mike', 'Zulu']);

    $component->call('sortBy', 'name');
    expect(collect($component->get('projects')->items())->pluck('name')->all())->toBe(['Zulu', 'Mike', 'Alpha']);

    $component->call('clearSort');
    expect($component->get('projects')->pluck('name')->all())->toBe(['Zulu', 'Alpha', 'Mike']);
});

test('an unknown sort key is ignored', function () {
    Project::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.index')
        ->set('sortKey', 'password; drop table projects')
        ->assertOk();
});

test('the chosen columns are shown and default to name, identifier and description', function () {
    Project::factory()->create(['name' => 'Columned', 'homepage' => 'https://example.test/home']);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('projects.index')->set('displayType', 'list');
    expect($component->get('visibleColumns'))->toBe(['name', 'identifier', 'description']);
    $component->assertDontSee('https://example.test/home');

    $component->set('columns', ['name', 'homepage'])->assertSee('https://example.test/home');
});

test('the parent column does not name a parent the viewer cannot see', function () {
    $hidden = Project::factory()->private()->create(['name' => 'Secret Parent']);
    Project::factory()->create(['name' => 'Open Child', 'parent_id' => $hidden->id]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.index')
        ->set('displayType', 'list')
        ->set('columns', ['name', 'parent_id'])
        ->assertSee('Open Child')
        ->assertDontSee('Secret Parent');
});

test('private projects the viewer is not a member of are neither listed nor counted', function () {
    Project::factory()->count(30)->private()->create();
    Project::factory()->create(['name' => 'Open']);
    $user = User::factory()->create();

    $projects = Livewire::actingAs($user)->test('projects.index')->set('statusFilter', 'active')->get('projects');

    expect($projects->total())->toBe(1)
        ->and(collect($projects->items())->pluck('name')->all())->toBe(['Open']);
});

test('a member whose role lacks view_project does not see the private project', function () {
    $project = Project::factory()->private()->create(['name' => 'Members Only']);
    $withoutView = projectListMember($project, ['view_issues']);
    $withView = projectListMember($project, ['view_project']);

    expect(Livewire::actingAs($withoutView)->test('projects.index')->get('projects')->pluck('name'))->not->toContain('Members Only')
        ->and(Livewire::actingAs($withView)->test('projects.index')->get('projects')->pluck('name'))->toContain('Members Only');
});

test('archived projects stay hidden from a member even when filtered by status', function () {
    $project = Project::factory()->create(['name' => 'Old Stuff', 'status' => ProjectStatus::Archived]);
    $user = projectListMember($project, ['view_project']);

    $projects = Livewire::actingAs($user)->test('projects.index')
        ->set('activeFilterKeys', ['status'])
        ->set('filterOperators', ['status' => '='])
        ->set('filterValues', ['status' => ['archived']])
        ->get('projects');

    expect($projects->total())->toBe(0);
});

test('an administrator can list archived projects', function () {
    Project::factory()->create(['name' => 'Old Stuff', 'status' => ProjectStatus::Archived]);
    $admin = User::factory()->admin()->create();

    $projects = Livewire::actingAs($admin)->test('projects.index')->set('statusFilter', 'archived')->get('projects');

    expect(collect($projects->items())->pluck('name')->all())->toBe(['Old Stuff']);
});

test('a project the viewer cannot see cannot be bookmarked from the list', function () {
    $hidden = Project::factory()->private()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.index')->call('toggleBookmark', $hidden->id)->assertNotFound();

    expect($hidden->isBookmarkedBy($user))->toBeFalse();
});

test('the parent filter only offers projects the viewer can see', function () {
    Project::factory()->private()->create(['name' => 'Secret Parent']);
    $visible = Project::factory()->create(['name' => 'Open Parent']);
    $user = User::factory()->create();

    $options = Livewire::actingAs($user)->test('projects.index')->get('engine')->field('parent_id')->options();

    expect($options)->toBe([$visible->id => 'Open Parent']);
});

test('the list opens in the display type chosen in the settings and can be switched', function () {
    Project::factory()->create(['name' => 'Switchable', 'homepage' => 'https://example.test/switch']);
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('projects.index')->set('columns', ['name', 'homepage']);
    expect($component->get('effectiveDisplayType'))->toBe('board');
    $component->assertSee('Switchable')->assertDontSee('https://example.test/switch');

    $component->call('setDisplayType', 'list')->assertSee('https://example.test/switch');
    expect($component->get('displayType'))->toBe('list');

    $component->call('setDisplayType', 'bogus');
    expect($component->get('effectiveDisplayType'))->toBe('board');

    Setting::set('project_list_display_type', 'list');
    expect(Livewire::actingAs($user)->test('projects.index')->get('effectiveDisplayType'))->toBe('list');
});

test('the board keeps the tree indentation when nothing is filtered', function () {
    $root = Project::factory()->create(['name' => 'Board Root']);
    Project::factory()->create(['name' => 'Board Child', 'parent_id' => $root->id]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('projects.index')
        ->assertSeeHtmlInOrder(['padding-left: 16px', 'Board Root', 'padding-left: 32px', 'Board Child']);
});

test('the default columns come from the project_list_defaults setting', function () {
    Setting::set('project_list_defaults', ['column_names' => ['identifier', 'status', 'nonsense']]);
    $user = User::factory()->create();

    expect(Livewire::actingAs($user)->test('projects.index')->get('visibleColumns'))->toBe(['identifier', 'status']);
});

test('the settings page stores the project list display type and default columns', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('project_list_display_type', 'board')
        ->assertSet('project_list_default_columns', ['name', 'identifier', 'description'])
        ->set('project_list_display_type', 'list')
        ->set('project_list_default_columns', ['name', 'homepage'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('project_list_display_type'))->toBe('list')
        ->and(Setting::get('project_list_defaults'))->toBe(['column_names' => ['name', 'homepage']]);

    Livewire::actingAs($admin)->test('settings.index')->set('project_list_display_type', 'grid')->call('save')->assertHasErrors(['project_list_display_type']);
    Livewire::actingAs($admin)->test('settings.index')->set('project_list_default_columns', ['password'])->call('save')->assertHasErrors(['project_list_default_columns.0']);
    Livewire::actingAs($admin)->test('settings.index')->set('project_list_default_columns', [])->call('save')->assertHasErrors(['project_list_default_columns']);
});
