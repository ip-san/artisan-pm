<?php

use App\Enums\IssueVisibility;
use App\Enums\RoleBuiltin;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create a role with a chosen set of permissions', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->set('name', 'Custom Role')
        ->set('permissions', ['view_project', 'edit_project'])
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $role = Role::where('name', 'Custom Role')->firstOrFail();

    expect($role->permissionKeys())->toEqualCanonicalizing(['view_project', 'edit_project']);
});

test('a non-admin cannot access role administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('roles.index')->assertForbidden();
    Livewire::actingAs($user)->test('roles.form')->assertForbidden();
});

test('permissions requiring membership cannot be assigned to the anonymous role', function () {
    $admin = User::factory()->admin()->create();
    $anonymous = Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value]);

    Livewire::actingAs($admin)
        ->test('roles.form', ['role' => $anonymous])
        ->set('permissions', ['view_project', 'edit_project'])
        ->call('save');

    expect($anonymous->refresh()->permissionKeys())->toBe(['view_project']);
});

test('an admin can set a role\'s issue visibility to own-only', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->set('name', 'Restricted Role')
        ->set('issuesVisibility', 'own')
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $role = Role::where('name', 'Restricted Role')->firstOrFail();

    expect($role->issues_visibility)->toBe(IssueVisibility::Own);
});

test('opening the create form with copy_from prefills name and permissions', function () {
    $admin = User::factory()->admin()->create();
    $source = Role::factory()->create(['name' => 'Manager', 'permissions' => ['view_project', 'edit_project']]);

    $component = Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($admin)
        ->test('roles.form');

    expect($component->get('name'))->toBe('Manager のコピー')
        ->and($component->get('permissions'))->toEqualCanonicalizing(['view_project', 'edit_project']);
});

test('saving a copied role creates an independent role', function () {
    $admin = User::factory()->admin()->create();
    $source = Role::factory()->create(['name' => 'Manager', 'permissions' => ['view_project']]);

    Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($admin)
        ->test('roles.form')
        ->call('save')
        ->assertRedirect(route('roles.index'));

    $copy = Role::where('name', 'Manager のコピー')->firstOrFail();

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->permissionKeys())->toBe(['view_project'])
        ->and($copy->builtin)->toBeNull();
});

test('a new role defaults to managing all roles', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->set('name', 'New Role')
        ->call('save');

    expect(Role::where('name', 'New Role')->firstOrFail()->all_roles_managed)->toBeTrue();
});

test('disabling all_roles_managed persists the selected managed roles', function () {
    $admin = User::factory()->admin()->create();
    $allowed = Role::factory()->create(['name' => 'Allowed']);
    $other = Role::factory()->create(['name' => 'Other']);

    Livewire::actingAs($admin)
        ->test('roles.form')
        ->set('name', 'Restricted Manager')
        ->set('allRolesManaged', false)
        ->set('managedRoleIds', [$allowed->id])
        ->call('save');

    $role = Role::where('name', 'Restricted Manager')->firstOrFail();

    expect($role->all_roles_managed)->toBeFalse()
        ->and($role->managedRoles->pluck('id'))->toContain($allowed->id)->not->toContain($other->id);
});

test('re-enabling all_roles_managed clears any previously selected managed roles', function () {
    $admin = User::factory()->admin()->create();
    $allowed = Role::factory()->create();
    $role = Role::factory()->create(['all_roles_managed' => false]);
    $role->managedRoles()->attach($allowed);

    Livewire::actingAs($admin)
        ->test('roles.form', ['role' => $role])
        ->set('allRolesManaged', true)
        ->call('save');

    expect($role->fresh()->managedRoles)->toBeEmpty();
});

test('a role held by members cannot be deleted and the refusal names their projects', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::factory()->create(['name' => 'In use']);
    $project = Project::factory()->create(['name' => 'Holding project']);
    Member::factory()->for($project)->for(User::factory()->create())->create()->roles()->attach($role);

    Livewire::actingAs($admin)->test('roles.index')
        ->call('delete', $role->id)
        ->assertHasErrors('delete')
        ->assertSee('このロールは使用中です。削除できません。')
        ->assertSee('Holding project');

    expect(Role::query()->find($role->id))->not->toBeNull()
        ->and(fn () => $role->delete())->toThrow(LogicException::class);
});

test('an unused role can be deleted, a builtin one cannot', function () {
    $admin = User::factory()->admin()->create();
    $unused = Role::factory()->create();
    $builtin = Role::factory()->create(['builtin' => RoleBuiltin::NonMember]);

    Livewire::actingAs($admin)->test('roles.index')
        ->call('delete', $unused->id)->assertHasNoErrors()
        ->call('delete', $builtin->id)->assertHasErrors('delete');

    expect(Role::query()->find($unused->id))->toBeNull()
        ->and(Role::query()->find($builtin->id))->not->toBeNull();
});
