<?php

use App\Enums\RoleBuiltin;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

test('an admin can see the permissions matrix pre-checked for every role', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);

    $component = Livewire::actingAs($admin)->test('roles.report');

    expect($component->get('matrix')[$role->id]['view_project'])->toBeTrue()
        ->and($component->get('matrix')[$role->id]['edit_project'])->toBeTrue()
        ->and($component->get('matrix')[$role->id]['delete_project'])->toBeFalse();
});

test('toggling checkboxes and saving updates every role in one submit', function () {
    $admin = User::factory()->admin()->create();
    $roleA = Role::factory()->create(['permissions' => ['view_project']]);
    $roleB = Role::factory()->create(['permissions' => []]);

    Livewire::actingAs($admin)
        ->test('roles.report')
        ->set("matrix.{$roleA->id}.edit_project", true)
        ->set("matrix.{$roleB->id}.view_project", true)
        ->call('save');

    expect($roleA->fresh()->permissionKeys())->toEqualCanonicalizing(['view_project', 'edit_project'])
        ->and($roleB->fresh()->permissionKeys())->toEqualCanonicalizing(['view_project']);
});

test('unchecking a permission removes it from the role', function () {
    $admin = User::factory()->admin()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'edit_project']]);

    Livewire::actingAs($admin)
        ->test('roles.report')
        ->set("matrix.{$role->id}.edit_project", false)
        ->call('save');

    expect($role->fresh()->permissionKeys())->toBe(['view_project']);
});

test('a permission not assignable to the anonymous role cannot be checked in for it', function () {
    $admin = User::factory()->admin()->create();
    $anonymous = Role::factory()->create(['builtin' => RoleBuiltin::Anonymous->value, 'permissions' => []]);

    Livewire::actingAs($admin)
        ->test('roles.report')
        ->set("matrix.{$anonymous->id}.edit_project", true)
        ->call('save');

    expect($anonymous->fresh()->permissionKeys())->not->toContain('edit_project');
});

test('a non-admin cannot access the permissions report', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('roles.report')->assertForbidden();
});
