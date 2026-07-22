<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiSidebarMember(Project $project, array $permissions = ['view_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('the Sidebar page content renders on the wiki show, index, and date-index views', function () {
    $project = Project::factory()->create();
    $user = wikiSidebarMember($project);
    $author = User::factory()->create();
    app(WikiPageService::class)->create($project, ['title' => 'Sidebar'], 'Unique sidebar marker text', $author);
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'Home page content', $author);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSee('Unique sidebar marker text');

    Livewire::actingAs($user)
        ->test('wiki.index', ['project' => $project])
        ->assertSee('Unique sidebar marker text');

    Livewire::actingAs($user)
        ->test('wiki.date-index', ['project' => $project])
        ->assertSee('Unique sidebar marker text');
});

test('the sidebar lookup is case-insensitive, matching Redmine\'s reserved-page protection', function () {
    $project = Project::factory()->create();
    $user = wikiSidebarMember($project);
    $author = User::factory()->create();
    app(WikiPageService::class)->create($project, ['title' => 'SIDEBAR'], 'Uppercase sidebar text', $author);
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'Home page content', $author);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSee('Uppercase sidebar text');
});

test('the sidebar does not appear when no Sidebar page exists', function () {
    $project = Project::factory()->create();
    $user = wikiSidebarMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'Home page content', $author);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSeeHtml('<aside');
});

test('the sidebar is not shown on the history, diff, annotate, or edit views', function () {
    $project = Project::factory()->create();
    $user = wikiSidebarMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $author = User::factory()->create();
    app(WikiPageService::class)->create($project, ['title' => 'Sidebar'], 'Unique sidebar marker text', $author);
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'v1', $author);
    app(WikiPageService::class)->update($page, [], 'v2', $author);

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSee('Unique sidebar marker text');

    Livewire::actingAs($user)
        ->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 1, 'to' => 2])
        ->assertDontSee('Unique sidebar marker text');

    Livewire::actingAs($user)
        ->test('wiki.annotate', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->assertDontSee('Unique sidebar marker text');

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSee('Unique sidebar marker text');
});
