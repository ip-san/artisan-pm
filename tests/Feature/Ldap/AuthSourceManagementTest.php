<?php

use App\Models\AuthSource;
use App\Models\User;
use Livewire\Livewire;

test('an admin can create an auth source', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('auth-sources.form')
        ->set('name', 'Corporate LDAP')
        ->set('host', 'ldap.example.com')
        ->set('base_dn', 'dc=example,dc=com')
        ->call('save')
        ->assertRedirect(route('auth-sources.index'));

    $source = AuthSource::where('name', 'Corporate LDAP')->firstOrFail();

    expect($source->host)->toBe('ldap.example.com')
        ->and($source->base_dn)->toBe('dc=example,dc=com')
        ->and($source->port)->toBe(389);
});

test('a non-admin cannot access auth source administration', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('auth-sources.index')->assertForbidden();
    Livewire::actingAs($user)->test('auth-sources.form')->assertForbidden();
});

test('leaving the account password blank on edit keeps the existing password', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->searchThenBind()->create();
    $originalPassword = $source->account_password;

    Livewire::actingAs($admin)
        ->test('auth-sources.form', ['authSource' => $source])
        ->set('name', 'Renamed')
        ->call('save');

    expect($source->refresh()->account_password)->toBe($originalPassword)
        ->and($source->name)->toBe('Renamed');
});

test('submitting a new account password updates it', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->searchThenBind()->create();

    Livewire::actingAs($admin)
        ->test('auth-sources.form', ['authSource' => $source])
        ->set('account_password', 'new-secret')
        ->call('save');

    expect($source->refresh()->account_password)->toBe('new-secret');
});

test('an admin can delete an auth source', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create();

    Livewire::actingAs($admin)->test('auth-sources.index')->call('delete', $source->id);

    expect(AuthSource::find($source->id))->toBeNull();
});

test('deleting an auth source detaches its linked users rather than deleting them', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create();
    $linkedUser = User::factory()->create(['auth_source_id' => $source->id, 'login' => 'linked']);

    Livewire::actingAs($admin)->test('auth-sources.index')->call('delete', $source->id);

    expect($linkedUser->fresh()->auth_source_id)->toBeNull();
});
