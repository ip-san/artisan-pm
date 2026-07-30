<?php

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\UserStatus;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('a user with unsubscribe disabled is not deletable', function () {
    Setting::set('unsubscribe', false);
    $user = User::factory()->create();

    expect($user->deletable())->toBeFalse();
});

test('a non-admin is deletable regardless of admin count', function () {
    Setting::set('unsubscribe', true);
    $user = User::factory()->create();

    expect($user->deletable())->toBeTrue();
});

test('the last active admin is not deletable', function () {
    Setting::set('unsubscribe', true);
    $admin = User::factory()->admin()->create();

    expect($admin->deletable())->toBeFalse();
});

test('an admin is deletable when another active admin exists', function () {
    Setting::set('unsubscribe', true);
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    expect($admin->deletable())->toBeTrue();
});

test('a locked admin does not count toward the last-admin safety net', function () {
    Setting::set('unsubscribe', true);
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create(['status' => UserStatus::Locked->value]);

    expect($admin->deletable())->toBeFalse();
});

test('AccountDeletionService anonymizes the row, removes memberships/watches/private queries, and preserves authored content', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create(['name' => 'Alice', 'login' => 'alice']);
    Member::factory()->for($project)->for($user)->create();
    $group = Group::factory()->create();
    $group->users()->attach($user);
    $user->bookmarkedProjects()->attach($project);

    $issue = Issue::factory()->for($project)->create(['author_id' => $user->id]);
    $issue->watchers()->create(['user_id' => $user->id]);

    $privateQuery = Query::create([
        'name' => 'My private query', 'type' => QueryType::Issue->value,
        'user_id' => $user->id, 'visibility' => QueryVisibility::Private->value, 'filters' => [], 'column_names' => [],
    ]);
    $publicQuery = Query::create([
        'name' => 'A public query', 'type' => QueryType::Issue->value,
        'user_id' => $user->id, 'visibility' => QueryVisibility::Public->value, 'filters' => [], 'column_names' => [],
    ]);

    $originalEmail = $user->email;

    app(AccountDeletionService::class)->delete($user);
    $user->refresh();

    expect($user->status)->toBe(UserStatus::Deleted)
        ->and($user->name)->not->toBe('Alice')
        ->and($user->email)->not->toBe($originalEmail)
        ->and($user->login)->not->toBe('alice')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->api_key)->toBeNull()
        ->and(Member::where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->groups()->count())->toBe(0)
        ->and($user->bookmarkedProjects()->count())->toBe(0)
        ->and($issue->fresh()->watchers()->count())->toBe(0)
        ->and(Query::find($privateQuery->id))->toBeNull()
        ->and(Query::find($publicQuery->id))->not->toBeNull();

    // Authored content stays attributed to the (now-anonymized) row rather
    // than being reassigned or nulled — this app's deliberate deviation
    // from Redmine's shared-Anonymous-user reassignment, see
    // AccountDeletionService's class doc.
    expect($issue->fresh()->author_id)->toBe($user->id);
});

test('the original email and login become reusable after account deletion', function () {
    $user = User::factory()->create(['email' => 'reusable@example.com', 'login' => 'reusable-login']);

    app(AccountDeletionService::class)->delete($user);

    $newUser = User::factory()->create(['email' => 'reusable@example.com', 'login' => 'reusable-login']);

    expect($newUser->email)->toBe('reusable@example.com')
        ->and($newUser->login)->toBe('reusable-login');
});

test('two deleted users get distinct placeholder logins without violating the unique constraint', function () {
    // login is NOT NULL as of the mandatory-login migration, so — unlike
    // email, which could stay null-free by design before this — the
    // anonymized login must itself be a unique placeholder rather than null.
    $first = User::factory()->create(['login' => 'first-login']);
    $second = User::factory()->create(['login' => 'second-login']);

    app(AccountDeletionService::class)->delete($first);
    app(AccountDeletionService::class)->delete($second);

    expect($first->fresh()->login)->not->toBeNull()->not->toBe('first-login')
        ->and($second->fresh()->login)->not->toBeNull()->not->toBe('second-login')
        ->and($first->fresh()->login)->not->toBe($second->fresh()->login);
});

test('a deleted user cannot log in', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create(['password' => Hash::make('password')]);
    Member::factory()->for($project)->for($user)->create();

    app(AccountDeletionService::class)->delete($user);

    expect($user->fresh()->isActive())->toBeFalse();
});

test('a deleted user no longer appears in the admin user list', function () {
    Setting::set('unsubscribe', true);
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    app(AccountDeletionService::class)->delete($user);

    $names = Livewire::actingAs($admin)
        ->test('users.index')
        ->get('users')
        ->pluck('id')
        ->all();

    expect($names)->not->toContain($user->id);
});

test('the profile page hides the delete-account section when unsubscribe is disabled', function () {
    Setting::set('unsubscribe', false);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('profile.index')
        ->assertDontSee('アカウントを削除する');
});

test('a user can delete their own account through the profile page after confirming their password', function () {
    Setting::set('unsubscribe', true);
    $user = User::factory()->create(['password' => Hash::make('password')]);
    session(['auth.password_confirmed_at' => now()->unix()]);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->call('deleteAccount')
        ->assertRedirect(route('login'));

    expect($user->fresh()->status)->toBe(UserStatus::Deleted)
        ->and(auth()->check())->toBeFalse();
});

test('deleting the account is blocked without a recently-confirmed password even if the button is visible', function () {
    // requirePasswordConfirmation() re-checks session state server-side on
    // every call — this exercises that a client that skips straight to
    // calling deleteAccount() without the sudo-mode gate having been
    // satisfied this session is still redirected to re-confirm rather than
    // having the account actually deleted.
    Setting::set('unsubscribe', true);
    $user = User::factory()->create(['password' => Hash::make('password')]);

    Livewire::actingAs($user)
        ->test('profile.index')
        ->call('deleteAccount')
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

test('a client-tampered request cannot delete an account the server-side deletable() check would reject', function () {
    // deleteAccount() re-runs User::deletable() itself rather than trusting
    // the accountDeletable computed property, which is exactly the kind of
    // client-visible-but-not-authoritative Livewire state the standing
    // rules warn about re-validating server-side.
    Setting::set('unsubscribe', true);
    $admin = User::factory()->admin()->create(['password' => Hash::make('password')]);
    session(['auth.password_confirmed_at' => now()->unix()]);

    Livewire::actingAs($admin)
        ->test('profile.index')
        ->call('deleteAccount')
        ->assertForbidden();

    expect($admin->fresh()->status)->toBe(UserStatus::Active);
});
