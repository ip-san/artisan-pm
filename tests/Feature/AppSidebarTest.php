<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

function sidebarMember(array $permissions): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->withPermissions($permissions)->create());

    return [$user, $project];
}

test('inside a project the sidebar lists the modules the member may open, and marks the current one', function () {
    [$user, $project] = sidebarMember(['view_project', 'view_issues', 'view_wiki_pages']);

    $response = $this->actingAs($user)->get(route('issues.index', $project))->assertOk();

    $response->assertSee('aria-label="プロジェクトメニュー"', false)
        ->assertSee('href="'.route('wiki.index', $project).'"', false)
        ->assertDontSee('href="'.route('time-entries.index', $project).'"', false)
        ->assertDontSee('href="'.route('projects.members', $project).'"', false)
        ->assertDontSee('プロジェクトの設定');

    expect($response->getContent())->toMatch('#<a href="'.preg_quote(route('issues.index', $project), '#').'"\s+aria-current="page"#');
});

test('a project manager also gets the project settings section', function () {
    [$user, $project] = sidebarMember(['view_project', 'manage_members', 'edit_project']);

    $this->actingAs($user)->get(route('projects.show', $project))->assertOk()
        ->assertSee('プロジェクトの設定')
        ->assertSee('href="'.route('projects.members', $project).'"', false)
        ->assertSee('href="'.route('projects.edit', $project).'"', false);
});

test('outside a project the sidebar lists the cross-project pages', function () {
    $this->actingAs(User::factory()->create())->get(route('projects.index'))->assertOk()
        ->assertSee('aria-label="メインメニュー"', false)
        ->assertSee('href="'.route('my-page.index').'"', false)
        ->assertSee('href="'.route('issues.global-index').'"', false);
});

test('a signed-out visitor sees no sidebar when sign-in is required', function () {
    $this->get(route('login'))->assertOk()->assertDontSee('data-app-sidebar', false);
});
