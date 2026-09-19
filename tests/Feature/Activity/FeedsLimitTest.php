<?php

use App\Models\Board;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

function feedsLimitMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_news', 'view_messages']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('the default cap is 15 entries', function () {
    $project = Project::factory()->create();
    $user = feedsLimitMember($project);
    News::factory()->for($project)->count(17)->create();

    $body = $this->actingAs($user)->get(route('news.atom', $project))->assertOk()->getContent();

    expect(substr_count($body, '<entry>'))->toBe(15);
});

test('feeds_limit caps every atom feed', function () {
    Setting::set('feeds_limit', 2);
    $project = Project::factory()->create();
    $user = feedsLimitMember($project);
    $board = Board::factory()->for($project)->create();
    News::factory()->for($project)->count(4)->create();
    Message::factory()->for($board)->count(4)->create();
    Issue::factory()->for($project)->count(4)->create();

    $routes = [
        route('news.atom', $project),
        route('boards.atom', [$project, $board]),
        route('issues.atom', $project),
        route('activity.atom', $project),
    ];

    foreach ($routes as $url) {
        $body = $this->actingAs($user)->get($url)->assertOk()->getContent();

        expect(substr_count($body, '<entry>'))->toBe(2, $url);
    }
});

test('a stored value below one still yields at least one entry', function () {
    Setting::set('feeds_limit', 0);
    $project = Project::factory()->create();
    $user = feedsLimitMember($project);
    News::factory()->for($project)->count(3)->create();

    $body = $this->actingAs($user)->get(route('news.atom', $project))->getContent();

    expect(substr_count($body, '<entry>'))->toBe(1);
});

test('the settings page validates and saves feeds_limit', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('feeds_limit', 15)
        ->set('feeds_limit', 0)
        ->call('save')
        ->assertHasErrors(['feeds_limit' => 'min'])
        ->set('feeds_limit', 501)
        ->call('save')
        ->assertHasErrors(['feeds_limit' => 'max'])
        ->set('feeds_limit', 30)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('feeds_limit'))->toBe(30);
});
