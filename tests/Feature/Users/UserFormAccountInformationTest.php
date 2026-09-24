<?php

use App\Models\User;
use App\Notifications\AccountInformation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('an administrator creates a user with a generated password and sends the account information', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('users.form')
        ->set('login', 'newbie')
        ->set('firstname', 'New')
        ->set('lastname', 'Bie')
        ->set('email', 'newbie@example.com')
        ->set('generate_password', true)
        ->set('send_information', true)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('login', 'newbie')->sole();

    Notification::assertSentTo($user, AccountInformation::class, fn (AccountInformation $notification) => $notification->password !== null
        && Hash::check($notification->password, $user->password));
});

test('without generate_password the password stays required, and nothing is sent unless asked', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('users.form')
        ->set('login', 'nopass')->set('firstname', 'No')->set('lastname', 'Pass')->set('email', 'nopass@example.com')
        ->call('save')
        ->assertHasErrors(['password']);

    $user = User::factory()->create();

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])->call('save')->assertHasNoErrors();
    Livewire::actingAs($admin)->test('users.form', ['user' => $admin])->set('send_information', true)->call('save');

    Notification::assertNothingSent();
});
