<?php

use App\Enums\ProjectStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Preferences\ProjectJumpBox;
use App\Support\Preferences\UserPreferences;
use Livewire\Livewire;

function jumpMember(User $user, string $name, ?ProjectStatus $status = null): Project
{
    $project = Project::factory()->create(['name' => $name, 'status' => ($status ?? ProjectStatus::Active)->value]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));

    return $project;
}

test('using a project remembers it, newest first, up to the chosen count', function () {
    $user = User::factory()->create();
    [$a, $b, $c, $d] = [jumpMember($user, 'A'), jumpMember($user, 'B'), jumpMember($user, 'C'), jumpMember($user, 'D')];

    foreach ([$a, $b, $c, $d, $b] as $project) {
        ProjectJumpBox::projectUsed($user->fresh(), $project);
    }

    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([$b->id, $d->id, $c->id]);

    UserPreferences::save($user->fresh(), ['recently_used_projects' => 2]);
    ProjectJumpBox::projectUsed($user->fresh(), $a);
    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([$a->id, $b->id]);
});

test('a count of zero remembers nothing', function () {
    $user = User::factory()->create();
    UserPreferences::save($user, ['recently_used_projects' => 0]);

    ProjectJumpBox::projectUsed($user->fresh(), jumpMember($user, 'A'));

    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([]);
});

test('a bookmarked project is listed as a bookmark and never as a recent one', function () {
    $user = User::factory()->create();
    $marked = jumpMember($user, 'Marked');
    $plain = jumpMember($user, 'Plain');
    $user->bookmarkedProjects()->attach($marked);

    ProjectJumpBox::projectUsed($user->fresh(), $plain);
    ProjectJumpBox::projectUsed($user->fresh(), $marked);

    $entries = ProjectJumpBox::entries($user->fresh());

    expect($entries['recent']->pluck('name')->all())->toBe(['Plain'])
        ->and($entries['bookmarked']->pluck('name')->all())->toBe(['Marked'])
        ->and($entries['all']->pluck('name')->all())->toBe(['Marked', 'Plain']);
});

test('archived projects and projects the user can no longer see are left out', function () {
    $user = User::factory()->create();
    $kept = jumpMember($user, 'Kept');
    $archived = jumpMember($user, 'Archived', ProjectStatus::Archived);
    $left = Project::factory()->create(['name' => 'Left', 'is_public' => false]);
    UserPreferences::save($user, ['recently_used_project_ids' => [$kept->id, $archived->id, $left->id]]);

    $entries = ProjectJumpBox::entries($user->fresh());

    expect($entries['recent']->pluck('name')->all())->toBe(['Kept'])
        ->and($entries['all']->pluck('name')->all())->toBe(['Kept']);
});

test('visiting a project page records it and other requests do not', function () {
    $user = User::factory()->create();
    $project = jumpMember($user, 'Visited');

    $this->actingAs($user)->get(route('projects.show', $project))->assertOk();
    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([$project->id]);

    UserPreferences::save($user->fresh(), ['recently_used_project_ids' => []]);
    $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk();
    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([]);
});

test('a project the user may not view is not recorded', function () {
    $user = User::factory()->create();
    $hidden = Project::factory()->create(['is_public' => false]);

    $this->actingAs($user)->get(route('projects.show', $hidden))->assertForbidden();

    expect($user->fresh()->preference('recently_used_project_ids'))->toBe([]);
});

test('the header lists the sections and the search box', function () {
    $user = User::factory()->create();
    $recent = jumpMember($user, 'Recently Used One');
    jumpMember($user, 'Some Other Project');
    UserPreferences::save($user, ['recently_used_project_ids' => [$recent->id]]);

    $html = $this->actingAs($user->fresh())->get(route('projects.index'))->assertOk()->getContent();

    expect($html)->toContain('data-project-jump-box')->toContain('最近使ったプロジェクト')->toContain('すべてのプロジェクト')->toContain('Recently Used One')->toContain('Some Other Project')->toContain('x-model="q"');
});

test('a user with no projects sees no jump box', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertDontSee('data-project-jump-box', false);
});

test('the profile stores how many recent projects to keep', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->assertSet('recently_used_projects', 3)->set('recently_used_projects', 5)->call('savePreferences')->assertHasNoErrors();
    expect($user->fresh()->preference('recently_used_projects'))->toBe(5);

    Livewire::actingAs($user)->test('profile.index')->set('recently_used_projects', 11)->call('savePreferences')->assertHasErrors(['recently_used_projects']);
});
