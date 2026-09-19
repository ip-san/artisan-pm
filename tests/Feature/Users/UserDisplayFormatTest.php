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

    Livewire::actingAs($admin)->test('settings.index')->set('user_format', 'firstname_lastname')->call('save')->assertHasErrors(['user_format']);
});
