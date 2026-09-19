<?php

use App\Enums\UserStatus;
use App\Models\User;
use Laravel\Passport\Passport;

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

test('a non-admin cannot list users', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson('/api/v1/users')->assertForbidden();
});

test('an admin can list users', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Alice']);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($other->id, $admin->id);
});

test('an admin can filter users by status', function () {
    $admin = User::factory()->admin()->create();
    $locked = User::factory()->create(['status' => UserStatus::Locked]);
    User::factory()->create(['status' => UserStatus::Active]);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users?status=locked');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($locked->id)->not->toContain($admin->id);
});

test('an admin can search users by name or email', function () {
    $admin = User::factory()->admin()->create();
    $match = User::factory()->create(['name' => 'Zephyr Match', 'email' => 'zephyr@example.com']);
    User::factory()->create(['name' => 'Someone Else', 'email' => 'someone@example.com']);

    Passport::actingAs($admin);

    $response = $this->getJson('/api/v1/users?name=zephyr');

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($match->id);
});

test('an invalid status filter value is rejected', function () {
    $admin = User::factory()->admin()->create();

    Passport::actingAs($admin);

    $this->getJson('/api/v1/users?status=bogus')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('an admin can show a single user', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Passport::actingAs($admin);

    $this->getJson("/api/v1/users/{$other->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $other->id)
        ->assertJsonPath('data.name', 'Bob')
        ->assertJsonPath('data.email', 'bob@example.com');
});

test('the user response never leaks the password hash or api key', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    Passport::actingAs($admin);

    $response = $this->getJson("/api/v1/users/{$other->id}");

    expect($response->json('data'))
        ->not->toHaveKey('password')
        ->not->toHaveKey('api_key')
        ->not->toHaveKey('remember_token');
});

test('a non-admin cannot show a user outside their visible circle (Redmine\'s not-found)', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/users/{$other->id}")->assertNotFound();
});

test('a non-admin can read a visible user with only the public fields', function () {
    $viewer = User::factory()->create();
    $other = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $project = App\Models\Project::factory()->create();
    App\Models\Member::factory()->for($project)->for($viewer)->create();
    App\Models\Member::factory()->for($project)->for($other)->create();

    Passport::actingAs($viewer);
    $body = $this->getJson("/api/v1/users/{$other->id}")->assertOk()->json('data');

    expect($body)->toHaveKeys(['id', 'login', 'name', 'email', 'created_at'])->not->toHaveKeys(['is_admin', 'status', 'api_key', 'auth_source_id', 'mail_notification']);
});

test('a user who hides their mail address is shown without it to non-admins but not to admins', function () {
    $viewer = User::factory()->create();
    $hidden = User::factory()->create(['email' => 'private@example.com']);
    App\Support\Preferences\UserPreferences::save($hidden, ['hide_mail' => true]);
    $project = App\Models\Project::factory()->create();
    App\Models\Member::factory()->for($project)->for($viewer)->create();
    App\Models\Member::factory()->for($project)->for($hidden)->create();

    Passport::actingAs($viewer);
    expect($this->getJson("/api/v1/users/{$hidden->id}")->assertOk()->json('data'))->not->toHaveKey('email');

    Passport::actingAs(User::factory()->admin()->create());
    expect($this->getJson("/api/v1/users/{$hidden->id}")->json('data.email'))->toBe('private@example.com');
});

test('a user sees their own admin flag and api key but not status', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);
    $body = $this->getJson("/api/v1/users/{$user->id}")->assertOk()->json('data');

    expect($body)->toHaveKeys(['is_admin', 'api_key'])->not->toHaveKey('status');
});

test('a user outside the viewer\'s visible circle is a 404', function () {
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();

    Passport::actingAs($viewer);

    $this->getJson("/api/v1/users/{$stranger->id}")->assertNotFound();
});

test('an admin creates a local user with a password and flags', function () {
    Passport::actingAs(User::factory()->admin()->create());

    $response = $this->postJson('/api/v1/users', [
        'login' => 'newbie', 'name' => 'New Bie', 'email' => 'newbie@example.com', 'password' => 'a-strong-password-1',
        'is_admin' => true, 'status' => 'locked', 'must_change_passwd' => true,
    ])->assertCreated();

    $user = User::where('login', 'newbie')->firstOrFail();
    expect($response->json('data.login'))->toBe('newbie')
        ->and(Illuminate\Support\Facades\Hash::check('a-strong-password-1', $user->password))->toBeTrue()
        ->and($user->is_admin)->toBeTrue()->and($user->status)->toBe(UserStatus::Locked)->and($user->must_change_passwd)->toBeTrue();
});

test('creation validates login format, duplicates, email and password', function () {
    User::factory()->create(['login' => 'taken', 'email' => 'taken@example.com']);
    Passport::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/users', ['login' => 'bad login!', 'name' => 'X', 'email' => 'x@example.com', 'password' => 'a-strong-password-1'])->assertUnprocessable()->assertJsonValidationErrors(['login']);
    $this->postJson('/api/v1/users', ['login' => 'TAKEN', 'name' => 'X', 'email' => 'x@example.com', 'password' => 'a-strong-password-1'])->assertUnprocessable()->assertJsonValidationErrors(['login']);
    $this->postJson('/api/v1/users', ['login' => 'fresh', 'name' => 'X', 'email' => 'TAKEN@example.com', 'password' => 'a-strong-password-1'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson('/api/v1/users', ['login' => 'fresh', 'name' => 'X', 'email' => 'x@example.com'])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $this->postJson('/api/v1/users', ['login' => 'fresh', 'name' => 'X', 'email' => 'x@example.com', 'password' => '123'])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

test('a directory-backed user needs no password', function () {
    $source = App\Models\AuthSource::factory()->create();
    Passport::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/users', ['login' => 'ldapper', 'name' => 'L', 'email' => 'l@example.com', 'auth_source_id' => $source->id, 'must_change_passwd' => true])->assertCreated();

    expect(User::where('login', 'ldapper')->firstOrFail()->must_change_passwd)->toBeFalse();
});

test('only an admin can create, update or delete users', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/v1/users', ['login' => 'x', 'name' => 'X', 'email' => 'x@example.com', 'password' => 'a-strong-password-1'])->assertForbidden();
    $this->putJson("/api/v1/users/{$target->id}", ['name' => 'Hacked'])->assertForbidden();
    $this->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
});

test('an admin updates fields without touching the rest, and a blank password keeps the old one', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Before', 'password' => 'old-password-123']);
    Passport::actingAs($admin);

    $this->putJson("/api/v1/users/{$target->id}", ['name' => 'After', 'is_admin' => true])->assertOk()->assertJsonPath('data.name', 'After');

    $fresh = $target->fresh();
    expect($fresh->is_admin)->toBeTrue()->and(Illuminate\Support\Facades\Hash::check('old-password-123', $fresh->password))->toBeTrue();

    $this->putJson("/api/v1/users/{$target->id}", ['password' => 'A-new-password-456'])->assertOk();
    expect(Illuminate\Support\Facades\Hash::check('A-new-password-456', $target->fresh()->password))->toBeTrue();
});

test('updating keeps a user\'s own login and email valid but rejects another user\'s', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create(['login' => 'mine', 'email' => 'mine@example.com']);
    $other = User::factory()->create(['login' => 'theirs', 'email' => 'theirs@example.com']);
    Passport::actingAs($admin);

    $this->putJson("/api/v1/users/{$target->id}", ['login' => 'mine', 'email' => 'mine@example.com'])->assertOk();
    $this->putJson("/api/v1/users/{$target->id}", ['login' => 'THEIRS'])->assertUnprocessable();
    $this->putJson("/api/v1/users/{$target->id}", ['email' => 'Theirs@example.com'])->assertUnprocessable();
    expect($other->fresh()->login)->toBe('theirs');
});

test('an admin can delete another user, but not themselves or the last active admin', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    Passport::actingAs($admin);

    $this->deleteJson("/api/v1/users/{$target->id}")->assertNoContent();
    expect($target->fresh()->status)->toBe(UserStatus::Deleted);

    $this->deleteJson("/api/v1/users/{$admin->id}")->assertForbidden();

    $admin->update(['status' => UserStatus::Locked]);
    $lastAdmin = User::factory()->admin()->create();
    $locked = User::factory()->admin()->create(['status' => UserStatus::Locked]);
    Passport::actingAs($locked);
    $this->deleteJson("/api/v1/users/{$lastAdmin->id}")->assertStatus(422);
    expect($lastAdmin->fresh()->status)->toBe(UserStatus::Active);
});
