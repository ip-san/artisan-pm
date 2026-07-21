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
