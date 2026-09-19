<?php

use App\Enums\QueryType;
use App\Enums\QueryVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Preferences\UserPreferences;
use Livewire\Livewire;

test('every option has a default and a stored value wins', function () {
    $user = User::factory()->create();

    expect($user->preference('comments_sorting'))->toBe('asc')
        ->and($user->preference('warn_on_leaving_unsaved'))->toBeTrue()
        ->and($user->preference('hide_mail'))->toBeFalse()
        ->and($user->preference('auto_watch_on'))->toBe(['issue_created', 'issue_assigned_to_me'])
        ->and($user->preference('default_issue_query'))->toBeNull();

    UserPreferences::save($user, ['comments_sorting' => 'desc', 'hide_mail' => true]);

    expect($user->fresh()->preference('comments_sorting'))->toBe('desc')
        ->and($user->fresh()->preference('hide_mail'))->toBeTrue()
        ->and($user->fresh()->preference('warn_on_leaving_unsaved'))->toBeTrue();
});

test('new accounts start from the site-wide default_users settings', function () {
    Setting::set('default_users_hide_mail', true);
    Setting::set('default_users_auto_watch_on', ['issue_created', 'bogus']);

    $user = User::factory()->create();

    expect($user->preference('hide_mail'))->toBeTrue()->and($user->preference('auto_watch_on'))->toBe(['issue_created']);

    UserPreferences::save($user, ['hide_mail' => false]);
    expect($user->fresh()->preference('hide_mail'))->toBeFalse();
});

test('saving cleans values and ignores unknown keys', function () {
    $user = User::factory()->create();

    UserPreferences::save($user, ['comments_sorting' => 'sideways', 'textarea_font' => 'comic', 'auto_watch_on' => ['issue_assigned_to_me', 'x'], 'evil' => 'data', 'default_issue_query' => '7']);

    $stored = $user->fresh()->preferences;
    expect($stored)->toMatchArray(['comments_sorting' => 'asc', 'textarea_font' => '', 'auto_watch_on' => ['issue_assigned_to_me'], 'default_issue_query' => 7])
        ->and($stored)->not->toHaveKey('evil');
});

test('the profile page saves personal options and validates them', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')
        ->assertSet('comments_sorting', 'asc')
        ->set('comments_sorting', 'desc')
        ->set('textarea_font', 'monospace')
        ->set('warn_on_leaving_unsaved', false)
        ->set('hide_mail', true)
        ->set('auto_watch_on', ['issue_created', 'issue_contributed_to'])
        ->call('savePreferences')
        ->assertHasNoErrors();

    expect($user->fresh()->preferences)->toMatchArray(['comments_sorting' => 'desc', 'textarea_font' => 'monospace', 'warn_on_leaving_unsaved' => false, 'hide_mail' => true, 'auto_watch_on' => ['issue_created', 'issue_contributed_to']]);

    Livewire::actingAs($user)->test('profile.index')->set('comments_sorting', 'random')->call('savePreferences')->assertHasErrors(['comments_sorting']);
    Livewire::actingAs($user)->test('profile.index')->set('auto_watch_on', ['nope'])->call('savePreferences')->assertHasErrors(['auto_watch_on.0']);
    Livewire::actingAs($user)->test('profile.index')->set('default_issue_query', 99999)->call('savePreferences')->assertHasErrors(['default_issue_query']);
});

test('the profile page remembers the saved options', function () {
    $user = User::factory()->create();
    UserPreferences::save($user, ['comments_sorting' => 'desc', 'hide_mail' => true]);

    Livewire::actingAs($user->fresh())->test('profile.index')->assertSet('comments_sorting', 'desc')->assertSet('hide_mail', true);
});

test('comments show newest first when the reader chose so', function () {
    $project = Project::factory()->create();
    $reader = User::factory()->create();
    Member::factory()->for($project)->for($reader)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => 'first note', 'created_at' => now()->subHours(2)]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => 'second note', 'created_at' => now()->subHour()]);

    $order = fn () => Livewire::actingAs($reader->fresh())->test('issues.show', ['project' => $project, 'issue' => $issue])->get('visibleJournals')->pluck('notes')->all();

    expect($order())->toBe(['first note', 'second note']);

    UserPreferences::save($reader, ['comments_sorting' => 'desc']);
    expect($order())->toBe(['second note', 'first note']);
});

test('the email on a public profile hides when its owner asked for it', function () {
    $viewer = User::factory()->create();
    $shown = User::factory()->create(['email' => 'visible@example.com']);
    $hidden = User::factory()->create(['email' => 'hidden@example.com']);
    UserPreferences::save($hidden, ['hide_mail' => true]);
    $project = Project::factory()->create();
    foreach ([$viewer, $shown, $hidden] as $user) {
        Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    }

    Livewire::actingAs($viewer)->test('users.show', ['user' => $shown])->assertSee('visible@example.com');
    Livewire::actingAs($viewer)->test('users.show', ['user' => $hidden])->assertDontSee('hidden@example.com');
    Livewire::actingAs($hidden)->test('users.show', ['user' => $hidden])->assertSee('hidden@example.com');
    Livewire::actingAs(User::factory()->admin()->create())->test('users.show', ['user' => $hidden])->assertSee('hidden@example.com');
});

test('the textarea font and the unsaved warning follow the preferences', function () {
    $user = User::factory()->create();

    expect(UserPreferences::textareaClass($user))->toBe('')
        ->and(UserPreferences::textareaClass($user, 'font-mono'))->toBe('font-mono')
        ->and(UserPreferences::unsavedWarningAttributes($user))->toContain('beforeunload');

    UserPreferences::save($user, ['textarea_font' => 'proportional', 'warn_on_leaving_unsaved' => false]);

    expect(UserPreferences::textareaClass($user->fresh(), 'font-mono'))->toBe('font-sans')
        ->and(UserPreferences::unsavedWarningAttributes($user->fresh()))->toBe('');

    UserPreferences::save($user, ['textarea_font' => 'monospace']);
    expect(UserPreferences::textareaClass($user->fresh()))->toBe('font-mono');
});

test('a default issue query opens the issue list unless the URL says otherwise', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));
    $query = Query::query()->create([
        'name' => 'My default', 'type' => QueryType::Issue->value, 'user_id' => $user->id, 'project_id' => null,
        'visibility' => QueryVisibility::Private->value,
        'filters' => [], 'column_names' => ['subject', 'done_ratio'], 'sort_criteria' => [], 'group_by' => null,
    ]);
    UserPreferences::save($user, ['default_issue_query' => $query->id]);

    expect(Livewire::actingAs($user->fresh())->test('issues.index', ['project' => $project])->get('columns'))->toBe(['subject', 'done_ratio']);

    $this->actingAs($user->fresh())->get(route('issues.index', $project).'?columns[]=tracker_id')->assertOk();
    expect(Livewire::withQueryParams(['columns' => ['tracker_id']])->actingAs($user->fresh())->test('issues.index', ['project' => $project])->get('columns'))->toBe(['tracker_id']);
});

test('someone else\'s private query cannot become your default', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $query = Query::query()->create([
        'name' => 'Private', 'type' => QueryType::Issue->value, 'user_id' => $owner->id, 'project_id' => null,
        'visibility' => QueryVisibility::Private->value,
        'filters' => [], 'column_names' => ['subject'], 'sort_criteria' => [], 'group_by' => null,
    ]);

    Livewire::actingAs($other)->test('profile.index')->set('default_issue_query', $query->id)->call('savePreferences')->assertHasErrors(['default_issue_query']);
});

test('the settings form stores the defaults new accounts start from', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')->set('default_users_hide_mail', true)->set('default_users_auto_watch_on', ['issue_created'])->call('save')->assertHasNoErrors();

    expect(Setting::get('default_users_hide_mail'))->toBeTrue()
        ->and(Setting::get('default_users_auto_watch_on'))->toBe(['issue_created'])
        ->and(User::factory()->create()->preference('auto_watch_on'))->toBe(['issue_created']);

    Livewire::actingAs($admin)->test('settings.index')->set('default_users_auto_watch_on', ['x'])->call('save')->assertHasErrors(['default_users_auto_watch_on.0']);
});
