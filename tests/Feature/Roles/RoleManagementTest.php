<?php

use App\Enums\RoleBuiltin;
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
