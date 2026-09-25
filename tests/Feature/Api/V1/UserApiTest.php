<?php

use App\Enums\CustomizableType;
use App\Enums\UserStatus;
use App\Models\AuthSource;
use App\Models\CustomField;
use App\Models\EmailAddress;
use App\Models\Group;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountInformation;
use App\Support\Api\CustomFieldPayload;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use Laravel\Passport\Passport;
use PragmaRX\Google2FA\Google2FA;

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
    $project = Project::factory()->create();
    Member::factory()->for($project)->for($viewer)->create();
    Member::factory()->for($project)->for($other)->create();

    Passport::actingAs($viewer);
    $body = $this->getJson("/api/v1/users/{$other->id}")->assertOk()->json('data');

    // The address is hidden by default (Redmine's default_users_hide_mail).
    expect($body)->toHaveKeys(['id', 'login', 'name', 'created_at'])->not->toHaveKeys(['email', 'is_admin', 'status', 'api_key', 'auth_source_id', 'mail_notification']);

    UserPreferences::save($other, ['hide_mail' => false]);

    expect($this->getJson("/api/v1/users/{$other->id}")->json('data.email'))->toBe('bob@example.com');
});

test('a user who hides their mail address is shown without it to non-admins but not to admins', function () {
    $viewer = User::factory()->create();
    $hidden = User::factory()->create(['email' => 'private@example.com']);
    UserPreferences::save($hidden, ['hide_mail' => true]);
    $project = Project::factory()->create();
    Member::factory()->for($project)->for($viewer)->create();
    Member::factory()->for($project)->for($hidden)->create();

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
        ->and(Hash::check('a-strong-password-1', $user->password))->toBeTrue()
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
    $source = AuthSource::factory()->create();
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
    expect($fresh->is_admin)->toBeTrue()->and(Hash::check('old-password-123', $fresh->password))->toBeTrue();

    $this->putJson("/api/v1/users/{$target->id}", ['password' => 'A-new-password-456'])->assertOk();
    expect(Hash::check('A-new-password-456', $target->fresh()->password))->toBeTrue();
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

test('the API creates and updates a user by first and last name, like Redmine', function () {
    Passport::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/users', ['login' => 'jdoe', 'firstname' => 'John', 'lastname' => 'Doe', 'email' => 'jdoe@example.com', 'password' => 'a-strong-password-1'])
        ->assertCreated()
        ->assertJsonPath('data.firstname', 'John')->assertJsonPath('data.lastname', 'Doe')->assertJsonPath('data.name', 'John Doe');

    $user = User::where('login', 'jdoe')->firstOrFail();

    $this->putJson("/api/v1/users/{$user->id}", ['firstname' => 'Johnny'])->assertOk()->assertJsonPath('data.name', 'Johnny Doe');

    $this->postJson('/api/v1/users', ['login' => 'half', 'firstname' => 'Half', 'email' => 'half@example.com', 'password' => 'a-strong-password-1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['lastname']);
    $this->postJson('/api/v1/users', ['login' => 'none', 'email' => 'none@example.com', 'password' => 'a-strong-password-1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

test('a user updates their own first and last names through my/account', function () {
    $user = User::factory()->create(['name' => 'Before']);
    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['firstname' => 'Jane', 'lastname' => 'Roe'])->assertOk()->assertJsonPath('data.name', 'Jane Roe');
    expect($user->fresh()->only(['firstname', 'lastname']))->toBe(['firstname' => 'Jane', 'lastname' => 'Roe']);
});

test('a user sets their own language, preferences, and custom field values through my/account', function () {
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'name' => 'Nickname']);
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = $this->putJson('/api/v1/my/account', [
        'language' => 'ja',
        'pref' => ['comments_sorting' => 'desc', 'hide_mail' => true],
        'custom_fields' => [['id' => $field->id, 'value' => 'Redmine fan']],
    ]);

    $response->assertOk()->assertJsonPath('data.language', 'ja');
    $fresh = $user->fresh();
    expect($fresh->language)->toBe('ja')
        ->and(UserPreferences::get($fresh, 'comments_sorting'))->toBe('desc')
        ->and(UserPreferences::get($fresh, 'hide_mail'))->toBeTrue();
    expect(collect(CustomFieldPayload::read($fresh))->firstWhere('id', $field->id)['value'])->toBe('Redmine fan');
});

test('an unrecognized pref key is ignored rather than rejected', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->putJson('/api/v1/my/account', ['pref' => ['not_a_real_preference' => 'x']])->assertOk();
});

test('the name filter also matches login, first and last name and additional emails like Redmine', function () {
    $admin = User::factory()->admin()->create();
    $byLogin = User::factory()->create(['name' => 'Alpha', 'login' => 'qx-login']);
    $byParts = User::factory()->create(['firstname' => 'Quentin', 'lastname' => 'Xavier']);
    $byAlias = User::factory()->create(['name' => 'Beta']);
    EmailAddress::query()->create(['user_id' => $byAlias->id, 'address' => 'qxalias@example.com', 'notify' => false]);
    $other = User::factory()->create(['name' => 'Gamma', 'firstname' => null, 'lastname' => null]);

    Passport::actingAs($admin);

    $ids = fn (string $term) => collect($this->getJson('/api/v1/users?name='.urlencode($term))->assertOk()->json('data'))->pluck('id');

    expect($ids('QX'))->toContain($byLogin->id)->toContain($byAlias->id)->not->toContain($other->id)
        // Every word must match the first or last name.
        ->and($ids('xav quen'))->toContain($byParts->id)->not->toContain($other->id)
        ->and($ids('xav nobody'))->not->toContain($byParts->id)
        // LIKE wildcards in the term are literal.
        ->and($ids('%'))->toBeEmpty();
});

test('users/current returns the caller\'s own account with their API key', function () {
    $user = User::factory()->create(['login' => 'me-myself']);
    Passport::actingAs($user);

    $this->getJson('/api/v1/users/current')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.login', 'me-myself')
        ->assertJsonPath('data.api_key', $user->api_key);
});

test('users/current needs an authenticated caller', function () {
    $this->getJson('/api/v1/users/current')->assertUnauthorized();
});

test('an admin can filter users by group_id', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $inGroup = User::factory()->create();
    $group->users()->attach($inGroup);
    $notInGroup = User::factory()->create();

    Passport::actingAs($admin);

    $ids = collect($this->getJson("/api/v1/users?group_id={$group->id}")->json('data'))->pluck('id');

    expect($ids)->toContain($inGroup->id)->not->toContain($notInGroup->id);
});

test('?include=groups on a user shows their groups, only to an admin or the user themselves', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);
    $group = Group::factory()->create(['name' => 'Developers']);
    $group->users()->attach($user);

    Passport::actingAs($admin);
    $this->getJson("/api/v1/users/{$user->id}?include=groups")
        ->assertOk()
        ->assertJsonPath('data.groups.0.name', 'Developers');

    // A fellow member of the same project can see the user's profile at
    // all, but not their groups (admin/self only).
    $colleague = User::factory()->create();
    Member::factory()->for($project)->for($colleague)->create()->roles()->attach($role);
    Passport::actingAs($colleague);
    $this->getJson("/api/v1/users/{$user->id}?include=groups")
        ->assertOk()
        ->assertJsonMissingPath('data.groups.0');
});

test('?include=memberships on a user shows their project roles, narrowed to projects the viewer may see', function () {
    $viewer = User::factory()->create();
    $user = User::factory()->create();
    $visible = Project::factory()->create(['name' => 'Visible project']);
    $hidden = Project::factory()->private()->create(['name' => 'Hidden project']);
    $role = Role::factory()->create(['name' => 'Developer', 'permissions' => ['view_project']]);
    Member::factory()->for($visible)->for($user)->create()->roles()->attach($role);
    Member::factory()->for($hidden)->for($user)->create()->roles()->attach($role);
    Member::factory()->for($visible)->for($viewer)->create()->roles()->attach($role);

    Passport::actingAs($viewer);

    $response = $this->getJson("/api/v1/users/{$user->id}?include=memberships")->assertOk();
    $projectNames = collect($response->json('data.memberships'))->pluck('project.name');

    expect($projectNames)->toContain('Visible project')->not->toContain('Hidden project');
});

test('?include=auth_source on a user shows their authentication source, admin only', function () {
    $admin = User::factory()->admin()->create();
    $source = AuthSource::factory()->create(['name' => 'Corp LDAP']);
    $user = User::factory()->create(['auth_source_id' => $source->id]);

    Passport::actingAs($admin);
    $this->getJson("/api/v1/users/{$user->id}?include=auth_source")
        ->assertOk()
        ->assertJsonPath('data.auth_source', ['id' => $source->id, 'name' => 'Corp LDAP']);

    Passport::actingAs($user);
    $this->getJson("/api/v1/users/{$user->id}?include=auth_source")
        ->assertOk()
        ->assertJsonMissingPath('data.auth_source');
});

test('?include=auth_source is absent for a local account and without the include', function () {
    $admin = User::factory()->admin()->create();
    $local = User::factory()->create();

    Passport::actingAs($admin);
    $this->getJson("/api/v1/users/{$local->id}?include=auth_source")
        ->assertOk()
        ->assertJsonMissingPath('data.auth_source');

    $ldapUser = User::factory()->create(['auth_source_id' => AuthSource::factory()->create()->id]);
    $this->getJson("/api/v1/users/{$ldapUser->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.auth_source');
});

test('the 2FA scheme shows to an admin or the user themselves, not to others', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project']]);
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_confirmed_at' => now(),
    ]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Passport::actingAs($admin);
    $this->getJson("/api/v1/users/{$user->id}")->assertOk()->assertJsonPath('data.twofa_scheme', 'totp');

    Passport::actingAs($user);
    $this->getJson("/api/v1/users/{$user->id}")->assertOk()->assertJsonPath('data.twofa_scheme', 'totp');

    $colleague = User::factory()->create();
    Member::factory()->for($project)->for($colleague)->create()->roles()->attach($role);
    Passport::actingAs($colleague);
    $this->getJson("/api/v1/users/{$user->id}")->assertOk()->assertJsonMissingPath('data.twofa_scheme');
});

test('the 2FA scheme is null for a user who has not enabled it', function () {
    $admin = User::factory()->admin()->create();

    Passport::actingAs($admin);

    $this->getJson("/api/v1/users/{$admin->id}")->assertOk()->assertJsonPath('data.twofa_scheme', null);
});

test('avatar_url is a Gravatar link when enabled, and absent otherwise', function () {
    $viewer = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'grace@example.com']);

    Passport::actingAs($viewer);

    $this->getJson("/api/v1/users/{$user->id}")->assertOk()->assertJsonMissingPath('data.avatar_url');

    Setting::set('gravatar_enabled', true);

    $this->getJson("/api/v1/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('data.avatar_url', fn (string $url) => str_contains($url, md5('grace@example.com')));
});

test('an admin can create a user with a generated password and mail the account information', function () {
    Notification::fake();
    Passport::actingAs(User::factory()->admin()->create());

    $id = $this->postJson('/api/v1/users', [
        'login' => 'generated', 'firstname' => 'Gen', 'lastname' => 'Erated', 'email' => 'generated@example.com',
        'generate_password' => true, 'send_information' => true,
    ])->assertCreated()->json('data.id');

    $user = User::findOrFail($id);
    $sent = null;

    Notification::assertSentTo($user, AccountInformation::class, function (AccountInformation $notification) use (&$sent) {
        $sent = $notification->password;

        return true;
    });

    expect($sent)->toBeString()
        ->and(strlen($sent))->toBeGreaterThanOrEqual(10)
        ->and(Hash::check($sent, $user->password))->toBeTrue()
        ->and($sent)->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/');
});

test('without generate_password a password is still required and no mail is sent unless asked', function () {
    Notification::fake();
    Passport::actingAs(User::factory()->admin()->create());

    $this->postJson('/api/v1/users', ['login' => 'nopass', 'firstname' => 'No', 'lastname' => 'Pass', 'email' => 'nopass@example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);

    $this->postJson('/api/v1/users', ['login' => 'quiet', 'firstname' => 'Qu', 'lastname' => 'Iet', 'email' => 'quiet@example.com', 'password' => 'Secret-Passw0rd'])
        ->assertCreated();

    Notification::assertNothingSent();
});

test('updating with generate_password and send_information mails the new password, not to the caller themselves', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    Passport::actingAs($admin);

    $this->putJson("/api/v1/users/{$user->id}", ['generate_password' => true, 'send_information' => true])->assertOk();
    $this->putJson("/api/v1/users/{$admin->id}", ['send_information' => true])->assertOk();

    Notification::assertSentTo($user, AccountInformation::class, fn ($n) => Hash::check($n->password, $user->fresh()->password));
    Notification::assertNotSentTo($admin, AccountInformation::class);
});

test('the account information mail names the login and the password', function () {
    $user = User::factory()->make(['login' => 'mail-login']);

    $mail = (new AccountInformation('S3cret-pass'))->toMail($user);

    expect(implode("\n", $mail->introLines))->toContain('mail-login')->toContain('S3cret-pass');
});
