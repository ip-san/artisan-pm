<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function wikiIncludeMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages', 'edit_wiki_pages']])
    );

    return $user;
}

function createWikiPageWithText(Project $project, string $title, string $text, User $author): WikiPage
{
    $page = WikiPage::factory()->for($project)->create(['title' => $title]);
    $page->versions()->create(['author_id' => $author->id, 'text' => $text, 'version' => 2]);

    return $page;
}

test('a {{include(Page)}} line is replaced with the target page\'s rendered content', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    createWikiPageWithText($project, 'Shared Snippet', 'Shared **bold** content.', $author);

    $html = app(WikiMarkdownRenderer::class)->render("Before.\n\n{{include(Shared Snippet)}}\n\nAfter.", $project);

    expect($html)->toContain('Before.')
        ->and($html)->toContain('<strong>bold</strong>')
        ->and($html)->toContain('Shared')
        ->and($html)->toContain('After.');
});

test('an include referencing a nonexistent page renders a visible error rather than vanishing', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render('{{include(Nope)}}', $project);

    expect($html)->toContain('見つかりません');
});

test('an include cannot reach a page in a different project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $author = User::factory()->create();
    createWikiPageWithText($otherProject, 'Only Elsewhere', 'Should not be reachable.', $author);

    $html = app(WikiMarkdownRenderer::class)->render('{{include(Only Elsewhere)}}', $project);

    expect($html)->toContain('見つかりません')
        ->and($html)->not->toContain('Should not be reachable.');
});

test('a self-referencing include is detected and does not recurse infinitely', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = createWikiPageWithText($project, 'Self', '{{include(Self)}}', $author);

    $html = app(WikiMarkdownRenderer::class)->render(
        $page->currentVersion->text,
        $project,
        page: $page,
    );

    expect($html)->toContain('循環インクルード');
});

test('a circular include across two pages (A includes B includes A) is detected', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    createWikiPageWithText($project, 'Page A', '{{include(Page B)}}', $author);
    $pageB = createWikiPageWithText($project, 'Page B', '{{include(Page A)}}', $author);

    $html = app(WikiMarkdownRenderer::class)->render($pageB->currentVersion->text, $project, page: $pageB);

    expect($html)->toContain('循環インクルード');
});

test('heading ids inside included content are stripped to avoid colliding with the including page\'s own headings', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    createWikiPageWithText($project, 'Has Heading', "## Overview\n\nDetails.", $author);

    $html = app(WikiMarkdownRenderer::class)->render("## Overview\n\n{{include(Has Heading)}}", $project);

    expect(substr_count($html, 'id="overview"'))->toBe(1);
});

test('{{child_pages}} inside an included page resolves relative to the included page, not the outer one', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $outer = createWikiPageWithText($project, 'Outer', '{{include(Inner)}}', $author);
    $inner = createWikiPageWithText($project, 'Inner', '{{child_pages}}', $author);
    $innerChild = WikiPage::factory()->for($project)->for($inner, 'parent')->create(['title' => 'Inner Child']);
    WikiPage::factory()->for($project)->for($outer, 'parent')->create(['title' => 'Outer Child']);

    $html = app(WikiMarkdownRenderer::class)->render($outer->currentVersion->text, $project, page: $outer);

    expect($html)->toContain($innerChild->title)
        ->and($html)->not->toContain('Outer Child');
});

test('the include macro renders on the wiki show page for a member who can view it', function () {
    $project = Project::factory()->create();
    $user = wikiIncludeMember($project);
    createWikiPageWithText($project, 'Snippet', 'Snippet body text.', $user);
    $page = createWikiPageWithText($project, 'Main Page', '{{include(Snippet)}}', $user);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSee('Snippet body text.');
});
