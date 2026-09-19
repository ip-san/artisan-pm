<?php

use App\Enums\UserStatus;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function atomKeyMember(Project $project, array $permissions = ['view_project', 'view_issues', 'view_news']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

test('a feed reader with the key reads a private project\'s feed without logging in', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($project);
    Issue::factory()->for($project)->create(['subject' => 'Visible through the key', 'created_at' => now()->subDay()]);

    $this->get(route('activity.atom', $project))->assertRedirect(route('login'));

    $response = $this->get(route('activity.atom', [$project, 'key' => $user->atomKey()]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $response->assertSee('Visible through the key', false);
});

test('the key works on every Atom feed', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($project, ['view_project', 'view_issues', 'view_news', 'view_messages']);
    $board = App\Models\Board::factory()->for($project)->create();
    $key = $user->atomKey();

    $this->get(route('issues.atom', [$project, 'key' => $key]))->assertOk();
    $this->get(route('news.atom', [$project, 'key' => $key]))->assertOk();
    $this->get(route('boards.atom', [$project, $board, 'key' => $key]))->assertOk();
});

test('the key grants only what its owner could see', function () {
    $visible = Project::factory()->create(['is_public' => false]);
    $hidden = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($visible);

    $this->get(route('activity.atom', [$hidden, 'key' => $user->atomKey()]))->assertForbidden();
});

test('a wrong, empty, reset or another user\'s key gets nothing', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($project);
    $outsider = User::factory()->create();
    $oldKey = $user->atomKey();

    $this->get(route('activity.atom', [$project, 'key' => 'nonsense']))->assertRedirect(route('login'));
    $this->get(route('activity.atom', [$project, 'key' => '']))->assertRedirect(route('login'));
    $this->get(route('activity.atom', [$project, 'key' => $outsider->atomKey()]))->assertForbidden();

    $user->regenerateAtomKey();
    $this->get(route('activity.atom', [$project, 'key' => $oldKey]))->assertRedirect(route('login'));
    $this->get(route('activity.atom', [$project, 'key' => $user->atom_key]))->assertOk();
});

test('a locked user\'s key stops working', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($project);
    $key = $user->atomKey();
    $user->forceFill(['status' => UserStatus::Locked])->save();

    $this->get(route('activity.atom', [$project, 'key' => $key]))->assertRedirect(route('login'));
});

test('the key authenticates the feed request only and leaves no session behind', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $user = atomKeyMember($project);

    $this->get(route('activity.atom', [$project, 'key' => $user->atomKey()]))->assertOk();

    $this->get(route('activity.index', $project))->assertRedirect(route('login'));
});

test('a logged-in user still reads feeds without a key', function () {
    $project = Project::factory()->create();
    $user = atomKeyMember($project);

    $this->actingAs($user)->get(route('activity.atom', $project))->assertOk();
});

test('the key is created on first use and is 40 hex characters', function () {
    $user = User::factory()->create();

    expect($user->atom_key)->toBeNull();

    $key = $user->atomKey();

    expect($key)->toMatch('/^[a-f0-9]{40}$/')->and($user->atomKey())->toBe($key)->and($user->fresh()->atom_key)->toBe($key);
});

test('the Atom links on the pages carry the viewer\'s key', function () {
    $project = Project::factory()->create();
    $user = atomKeyMember($project);

    $html = Livewire::actingAs($user)->test('activity.index', ['project' => $project])->html();

    expect($html)->toContain('activity.atom?key='.$user->fresh()->atom_key);
});

test('the profile page shows the key and resets it', function () {
    $user = User::factory()->create();
    $before = $user->atomKey();

    $page = Livewire::actingAs($user)->test('profile.index')->assertSee($before);
    session(['auth.password_confirmed_at' => time()]);
    $page->call('resetAtomKey');

    expect($user->fresh()->atom_key)->not->toBe($before)->and($user->fresh()->atom_key)->toMatch('/^[a-f0-9]{40}$/');
});
