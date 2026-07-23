<?php

use App\Models\User;
use Livewire\Livewire;

function withRecentlyConfirmedPasswordForApiKeyTest(): void
{
    session(['auth.password_confirmed_at' => now()->unix()]);
}

test('a user with no api key sees the generate prompt', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->assertSee('APIキーはまだ生成されていません');
});

test('generating an api key persists it and displays it', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPasswordForApiKeyTest();

    Livewire::actingAs($user)->test('profile.index')->call('regenerateApiKey');

    $user->refresh();
    expect($user->api_key)->not->toBeNull()->and(strlen($user->api_key))->toBe(40);
});

test('regenerating an api key replaces the previous one', function () {
    $user = User::factory()->create();
    withRecentlyConfirmedPasswordForApiKeyTest();
    $originalKey = $user->regenerateApiKey();

    Livewire::actingAs($user)->test('profile.index')->call('regenerateApiKey');

    expect($user->fresh()->api_key)->not->toBe($originalKey);
});

test('regenerating the api key without a recent password confirmation redirects to confirm', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('profile.index')
        ->call('regenerateApiKey')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->api_key)->toBeNull();
});
