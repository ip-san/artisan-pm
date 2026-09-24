<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\AccountDeletionService;
use Livewire\Livewire;

test('the default format shows the name only', function () {
    $user = User::factory()->create(['name' => 'Alice Smith', 'login' => 'asmith']);

    expect($user->displayName())->toBe('Alice Smith');
});

test('name_login and login formats follow the setting, falling back to the name without a login', function () {
    $user = User::factory()->create(['name' => 'Alice Smith', 'login' => 'asmith']);

    Setting::set('user_format', 'name_login');
    expect($user->displayName())->toBe('Alice Smith (asmith)');

    Setting::set('user_format', 'login');
    expect($user->displayName())->toBe('asmith');

    $user->login = '';
    expect($user->displayName())->toBe('Alice Smith');

    Setting::set('user_format', 'nonsense');
    expect($user->displayName())->toBe('Alice Smith');
});

test('an issue page names its author, assignee and commenter in the chosen format', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $author = User::factory()->create(['name' => 'Ann Author', 'login' => 'ann']);
    $issue = Issue::factory()->for($project)->create([
        'author_id' => $author->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'A comment']);

    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('Ann Author')->assertDontSee('Ann Author (ann)');

    Setting::set('user_format', 'name_login');
    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('Ann Author (ann)');

    Setting::set('user_format', 'login');
    Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertDontSee('Ann Author')->assertSee('ann');
});

test('the settings page saves the format and rejects an unknown one', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->assertSet('user_format', 'name')->set('user_format', 'login')->call('save')->assertHasNoErrors();
    expect(Setting::get('user_format'))->toBe('login');

    Livewire::actingAs($admin)->test('settings.index')->set('user_format', 'initials')->call('save')->assertHasErrors(['user_format']);
});

test('every first/last name format renders like Redmine for a user with both parts', function (string $format, string $expected) {
    $user = User::factory()->create(['login' => 'jsmith', 'firstname' => 'John Paul', 'lastname' => 'Smith']);

    Setting::set('user_format', $format);

    expect($user->displayName())->toBe($expected)
        ->and($user->displayName($format))->toBe($expected);
})->with([
    'firstname_lastname' => ['firstname_lastname', 'John Paul Smith'],
    'firstname_lastinitial' => ['firstname_lastinitial', 'John Paul S.'],
    'firstinitial_lastname' => ['firstinitial_lastname', 'J. P. Smith'],
    'firstname' => ['firstname', 'John Paul'],
    'lastname_firstname' => ['lastname_firstname', 'Smith John Paul'],
    'lastnamefirstname' => ['lastnamefirstname', 'SmithJohn Paul'],
    'lastname_comma_firstname' => ['lastname_comma_firstname', 'Smith, John Paul'],
    'lastname' => ['lastname', 'Smith'],
    'name' => ['name', 'John Paul Smith'],
    'name_login' => ['name_login', 'John Paul Smith (jsmith)'],
    'login' => ['login', 'jsmith'],
]);

test('Japanese name parts render without splitting characters', function () {
    $user = User::factory()->create(['firstname' => '太郎', 'lastname' => '山田']);

    expect($user->displayName('lastnamefirstname'))->toBe('山田太郎')
        ->and($user->displayName('lastname_firstname'))->toBe('山田 太郎')
        ->and($user->displayName('firstname_lastinitial'))->toBe('太郎 山.');
});

test('a user without both parts keeps showing the name in the first/last name formats', function () {
    $nameOnly = User::factory()->create(['name' => '佐藤 花子']);
    $firstOnly = User::factory()->create(['name' => 'Legacy Name', 'firstname' => 'Ann']);

    Setting::set('user_format', 'lastname_comma_firstname');

    expect($nameOnly->displayName())->toBe('佐藤 花子')
        ->and($firstOnly->displayName())->toBe('Legacy Name');
});

test('name follows the parts in the default format whenever both are set, and is left alone otherwise', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    expect($user->fresh()->name)->toBe('Old Name');

    $user->update(['firstname' => 'Jane', 'lastname' => 'Doe']);
    expect($user->fresh()->name)->toBe('Jane Doe');

    $user->update(['name' => 'Something Else']);
    expect($user->fresh()->name)->toBe('Jane Doe');

    $user->update(['firstname' => null, 'lastname' => null, 'name' => 'Plain']);
    expect($user->fresh()->name)->toBe('Plain');
});

test('the admin form saves the name parts and derives the name from them', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('users.form')
        ->set('login', 'tyamada')->set('lastname', '山田')->set('firstname', '太郎')->set('email', 'tyamada@example.com')
        ->set('password', 'Password123!')->set('password_confirmation', 'Password123!')
        ->call('save')->assertHasNoErrors();

    $user = User::where('login', 'tyamada')->sole();
    expect($user->firstname)->toBe('太郎')->and($user->lastname)->toBe('山田')->and($user->name)->toBe('太郎 山田');

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->assertSet('firstname', '太郎')->assertSet('lastname', '山田')
        ->set('firstname', '')->set('lastname', '')->set('name', '山田 太郎')
        ->call('save')->assertHasNoErrors();

    expect($user->fresh()->only(['name', 'firstname', 'lastname']))->toBe(['name' => '山田 太郎', 'firstname' => null, 'lastname' => null]);
});

test('the admin form needs a name or both parts', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['name' => 'Someone']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('name', '')->call('save')->assertHasErrors(['name']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('firstname', 'Only')->call('save')->assertHasErrors(['lastname']);

    Livewire::actingAs($admin)->test('users.form', ['user' => $user])
        ->set('firstname', str_repeat('a', 31))->set('lastname', 'Doe')->call('save')->assertHasErrors(['firstname']);

    expect($user->fresh()->name)->toBe('Someone');
});

test('the account page saves the own name parts', function () {
    $user = User::factory()->create(['name' => 'Before']);

    Livewire::actingAs($user)->test('profile.index')
        ->set('lastname', 'Doe')->set('firstname', 'Jane')
        ->call('updateProfile')->assertHasNoErrors()
        ->assertSet('name', 'Jane Doe');

    expect($user->fresh()->only(['name', 'firstname', 'lastname']))->toBe(['name' => 'Jane Doe', 'firstname' => 'Jane', 'lastname' => 'Doe']);
});

test('registration accepts the name parts instead of a name', function () {
    $this->post(route('register'), [
        'lastname' => '山田',
        'firstname' => '花子',
        'login' => 'hyamada',
        'email' => 'hyamada@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasNoErrors();

    expect(User::where('login', 'hyamada')->sole()->only(['name', 'firstname', 'lastname']))->toBe(['name' => '花子 山田', 'firstname' => '花子', 'lastname' => '山田']);

    auth()->logout();

    $this->post(route('register'), [
        'login' => 'noname',
        'email' => 'noname@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertSessionHasErrors('name');
});

test('deleting an account clears the name parts too', function () {
    $user = User::factory()->create(['firstname' => 'Real', 'lastname' => 'Person']);

    app(AccountDeletionService::class)->delete($user);

    $user->refresh();
    expect($user->firstname)->toBeNull()->and($user->lastname)->toBeNull()->and($user->name)->not->toContain('Real');
});

test('the settings page offers every format', function () {
    $admin = User::factory()->admin()->create();

    foreach (User::USER_FORMATS as $format) {
        Livewire::actingAs($admin)->test('settings.index')->set('user_format', $format)->call('save')->assertHasNoErrors();
        expect(Setting::get('user_format'))->toBe($format);
    }

    Livewire::actingAs($admin)->test('settings.index')->assertSee(__('姓, 名'));
});
