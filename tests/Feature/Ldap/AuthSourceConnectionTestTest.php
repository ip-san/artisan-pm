<?php

use App\Models\AuthSource;
use App\Models\User;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Laravel\Testing\EmulatedConnectionFake;
use Livewire\Livewire;

function fakeConnectionTestDirectory(AuthSource $source): EmulatedConnectionFake
{
    $name = "auth-source-{$source->id}";

    Container::addConnection(new Connection(['base_dn' => $source->base_dn]), $name);

    return DirectoryEmulator::setup($name);
}

afterEach(function () {
    DirectoryEmulator::tearDown();
});

test('testing a connection with no search account reports success once connected', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create(['account' => null, 'account_password' => null]);
    fakeConnectionTestDirectory($source);

    Livewire::actingAs($admin)
        ->test('auth-sources.form', ['authSource' => $source])
        ->call('testConnection')
        ->assertSet('connectionTestPassed', true)
        ->assertSee('接続に成功しました');
});

test('testing a connection verifies the search account can bind', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->searchThenBind()->create();
    $fake = fakeConnectionTestDirectory($source);
    $fake->actingAs($source->account);

    Livewire::actingAs($admin)
        ->test('auth-sources.form', ['authSource' => $source])
        ->call('testConnection')
        ->assertSet('connectionTestPassed', true);
});

test('testing a connection fails when the search account cannot bind', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->searchThenBind()->create();
    fakeConnectionTestDirectory($source);
    // No actingAs() call — nothing authorizes the search account to bind.

    Livewire::actingAs($admin)
        ->test('auth-sources.form', ['authSource' => $source])
        ->call('testConnection')
        ->assertSet('connectionTestPassed', false)
        ->assertSee('接続できませんでした');
});

test('the test connection button is not shown when creating a new auth source', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('auth-sources.form')
        ->assertDontSee('接続をテスト');
});
