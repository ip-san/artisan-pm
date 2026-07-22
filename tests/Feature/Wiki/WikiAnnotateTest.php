<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\WikiPageService;
use App\Support\Wiki\WikiAnnotator;
use Livewire\Livewire;

function wikiAnnotateMember(Project $project, array $permissions = ['view_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('every line is credited to the version that introduced it, unchanged lines stay credited to version 1', function () {
    $project = Project::factory()->create();
    $authorV1 = User::factory()->create();
    $authorV2 = User::factory()->create();
    $authorV3 = User::factory()->create();

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], "line A\nline B\nline C", $authorV1);
    app(WikiPageService::class)->update($page, [], "line A\nline B2\nline C", $authorV2);
    app(WikiPageService::class)->update($page, [], "line A\nline B2\nline C\nline D", $authorV3);

    $version = $page->versions()->where('version', 3)->firstOrFail();

    $lines = app(WikiAnnotator::class)->annotate($version);

    expect($lines)->toHaveCount(4)
        ->and($lines[0]['text'])->toBe('line A')->and($lines[0]['version'])->toBe(1)->and($lines[0]['author']->id)->toBe($authorV1->id)
        ->and($lines[1]['text'])->toBe('line B2')->and($lines[1]['version'])->toBe(2)->and($lines[1]['author']->id)->toBe($authorV2->id)
        ->and($lines[2]['text'])->toBe('line C')->and($lines[2]['version'])->toBe(1)->and($lines[2]['author']->id)->toBe($authorV1->id)
        ->and($lines[3]['text'])->toBe('line D')->and($lines[3]['version'])->toBe(3)->and($lines[3]['author']->id)->toBe($authorV3->id);
});

test('a line deleted and later reintroduced with identical text is credited to the version that reintroduced it', function () {
    $project = Project::factory()->create();
    $authorV1 = User::factory()->create();
    $authorV3 = User::factory()->create();

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], "keep\nremove me", $authorV1);
    app(WikiPageService::class)->update($page, [], 'keep', User::factory()->create());
    app(WikiPageService::class)->update($page, [], "keep\nremove me", $authorV3);

    $version = $page->versions()->where('version', 3)->firstOrFail();

    $lines = app(WikiAnnotator::class)->annotate($version);

    expect($lines[0]['text'])->toBe('keep')->and($lines[0]['version'])->toBe(1)
        ->and($lines[1]['text'])->toBe('remove me')->and($lines[1]['version'])->toBe(3)->and($lines[1]['author']->id)->toBe($authorV3->id);
});

test('annotating version 1 credits every line to it', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();

    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], "only\nlines", $author);

    $version = $page->versions()->where('version', 1)->firstOrFail();

    $lines = app(WikiAnnotator::class)->annotate($version);

    expect($lines)->toHaveCount(2)
        ->and(collect($lines)->pluck('version')->unique()->all())->toBe([1])
        ->and(collect($lines)->pluck('author')->pluck('id')->unique()->all())->toBe([$author->id]);
});

test('a member with view_wiki_pages can view the annotate page', function () {
    $project = Project::factory()->create();
    $user = wikiAnnotateMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], "a\nb", $author);

    Livewire::actingAs($user)
        ->test('wiki.annotate', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->assertOk();
});

test('a member without view_wiki_pages is forbidden from the annotate page', function () {
    $project = Project::factory()->create();
    $user = wikiAnnotateMember($project, []);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], "a\nb", $author);

    Livewire::actingAs($user)
        ->test('wiki.annotate', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->assertForbidden();
});

test('a nonexistent version number 404s', function () {
    $project = Project::factory()->create();
    $user = wikiAnnotateMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'a', $author);

    Livewire::actingAs($user)
        ->test('wiki.annotate', ['project' => $project, 'wikiPage' => $page, 'version' => 999])
        ->assertNotFound();
});
