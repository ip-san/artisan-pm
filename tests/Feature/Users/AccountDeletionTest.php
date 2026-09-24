<?php

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Enums\UserStatus;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Reaction;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\AccountDeletionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

test('account deletion removes the user\'s custom field values and deletes their attachment files', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $textField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'field_format' => CustomFieldFormat::String]);
    $fileField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'field_format' => CustomFieldFormat::Attachment]);
    $user->setCustomFieldValues([
        $textField->id => 'Personal note',
        $fileField->id => UploadedFile::fake()->create('passport.pdf', 10),
    ], collect([$textField, $fileField]));
    $media = $user->getMedia(AttachmentFormat::COLLECTION)->sole();
    $path = $media->getPath();

    $this->actingAs($admin)->get(route('attachments.show', $media))->assertOk();
    expect(file_exists($path))->toBeTrue()
        ->and($user->customFieldValues()->count())->toBe(2);

    app(AccountDeletionService::class)->delete($user);

    expect($user->customFieldValues()->count())->toBe(0)
        ->and(Media::find($media->id))->toBeNull()
        ->and(file_exists($path))->toBeFalse();

    $this->actingAs($admin)->get(route('attachments.show', $media))->assertNotFound();
    Passport::actingAs($admin);
    $this->getJson(route('api.attachments.download', $media))->assertNotFound();
});

test('account deletion leaves other users\' custom field values and files alone', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $fileField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'field_format' => CustomFieldFormat::Attachment]);
    $other->setCustomFieldValues([$fileField->id => UploadedFile::fake()->create('other.pdf', 10)], collect([$fileField]));
    $media = $other->getMedia(AttachmentFormat::COLLECTION)->sole();

    app(AccountDeletionService::class)->delete($user);

    expect(Media::find($media->id))->not->toBeNull()
        ->and(file_exists($media->getPath()))->toBeTrue()
        ->and($other->customFieldValues()->count())->toBe(1);
});

test('account deletion removes what Redmine removes with the user rather than reassigning it', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create(['preferences' => ['comments_sorting' => 'desc']]);
    $user->atomKey();
    $userField = CustomField::factory()->create(['customized_type' => CustomizableType::Issue, 'field_format' => CustomFieldFormat::User]);
    $issue = Issue::factory()->for($project)->create(['assigned_to_id' => $user->id]);
    $issue->setCustomFieldValues([$userField->id => (string) $user->id], collect([$userField]));
    $category = IssueCategory::create(['project_id' => $project->id, 'name' => 'Backend', 'assigned_to_id' => $user->id]);
    Reaction::factory()->create(['user_id' => $user->id]);
    $webhook = Webhook::factory()->create(['user_id' => $user->id]);
    $siteWebhook = Webhook::factory()->create();

    expect(CustomFieldValue::query()->where('custom_field_id', $userField->id)->where('value_int', $user->id)->exists())->toBeTrue();

    app(AccountDeletionService::class)->delete($user);
    $user->refresh();

    expect($user->atom_key)->toBeNull()
        ->and($user->preferences)->toBeNull()
        ->and($issue->fresh()->assigned_to_id)->toBeNull()
        ->and($issue->fresh()->journals()->count())->toBe(0)
        ->and(CustomFieldValue::query()->where('custom_field_id', $userField->id)->exists())->toBeFalse()
        ->and($category->fresh()->assigned_to_id)->toBeNull()
        ->and(Reaction::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Webhook::find($webhook->id))->toBeNull()
        ->and(Webhook::find($siteWebhook->id))->not->toBeNull();
});

test('account deletion deletes the user\'s OAuth access and refresh tokens', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $clientId = (string) Str::uuid();
    DB::table('oauth_access_tokens')->insert([
        ['id' => 'mine', 'user_id' => $user->id, 'client_id' => $clientId, 'revoked' => false],
        ['id' => 'theirs', 'user_id' => $other->id, 'client_id' => $clientId, 'revoked' => false],
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        ['id' => 'mine-refresh', 'access_token_id' => 'mine', 'revoked' => false],
        ['id' => 'theirs-refresh', 'access_token_id' => 'theirs', 'revoked' => false],
    ]);

    app(AccountDeletionService::class)->delete($user);

    expect(DB::table('oauth_access_tokens')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $other->id)->exists())->toBeTrue()
        ->and(DB::table('oauth_refresh_tokens')->count())->toBe(1)
        ->and(DB::table('oauth_refresh_tokens')->where('access_token_id', 'theirs')->exists())->toBeTrue();
});
