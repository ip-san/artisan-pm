<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiDateIndexMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages']])
    );

    return $user;
}

test('a member with view_wiki_pages can see the date index', function () {
    $project = Project::factory()->create();
    $user = wikiDateIndexMember($project);
    $author = User::factory()->create();
    app(WikiPageService::class)->create($project, ['title' => 'Home'], 'text', $author);

    Livewire::actingAs($user)
        ->test('wiki.date-index', ['project' => $project])
        ->assertOk()
        ->assertSee('Home');
});

test('pages are grouped by the date their current version was written, newest first', function () {
    $project = Project::factory()->create();
    $user = wikiDateIndexMember($project);
    $author = User::factory()->create();

    $older = app(WikiPageService::class)->create($project, ['title' => 'Older Page'], 'text', $author);
    $older->currentVersion->forceFill(['created_at' => now()->subDays(5)])->save();

    $newer = app(WikiPageService::class)->create($project, ['title' => 'Newer Page'], 'text', $author);
    $newer->currentVersion->forceFill(['created_at' => now()])->save();

    $dates = Livewire::actingAs($user)
        ->test('wiki.date-index', ['project' => $project])
        ->get('pagesByDate')
        ->keys();

    expect($dates->first())->toBe(now()->toDateString())
        ->and($dates->last())->toBe(now()->subDays(5)->toDateString());
});

test('a member without view_wiki_pages cannot see the date index', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => []])
    );

    Livewire::actingAs($user)
        ->test('wiki.date-index', ['project' => $project])
        ->assertForbidden();
});

test('an empty wiki shows a placeholder', function () {
    $project = Project::factory()->create();
    $user = wikiDateIndexMember($project);

    Livewire::actingAs($user)
        ->test('wiki.date-index', ['project' => $project])
        ->assertSee('Wikiページがありません');
});
