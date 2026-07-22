<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function wikiTocMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages', 'edit_wiki_pages']])
    );

    return $user;
}

test('a {{toc}} line on its own is replaced with a nested list of headings in document order', function () {
    $project = Project::factory()->create();
    $text = "# Intro\n\n{{toc}}\n\n## Section A\n\ntext\n\n## Section B\n\nmore\n\n### Sub B1\n\ndeep\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<ul class="table-of-contents">')
        ->and($html)->toContain('<a href="#intro">Intro</a>')
        ->and($html)->toContain('<a href="#section-a">Section A</a>')
        ->and($html)->toContain('<a href="#section-b">Section B</a>')
        ->and($html)->toContain('<a href="#sub-b1">Sub B1</a>');

    // The TOC's own position replaces {{toc}}, appearing between the h1
    // and h2 — not hoisted to the very top of the document.
    $tocPosition = strpos($html, 'table-of-contents');
    $introPosition = strpos($html, '<h1');
    $sectionAPosition = strpos($html, '<h2 id="section-a"');
    expect($tocPosition)->toBeGreaterThan($introPosition)
        ->and($tocPosition)->toBeLessThan($sectionAPosition);
});

test('headings get stable ids usable as anchors even without a {{toc}}', function () {
    $project = Project::factory()->create();
    $html = app(WikiMarkdownRenderer::class)->render("## My Heading\n", $project);

    expect($html)->toContain('<h2 id="my-heading">');
});

test('{{toc}} text elsewhere in a line (not alone) is left as plain text', function () {
    $project = Project::factory()->create();
    $html = app(WikiMarkdownRenderer::class)->render("See {{toc}} in the docs.\n", $project);

    expect($html)->toContain('{{toc}}')
        ->and($html)->not->toContain('table-of-contents');
});

test('a page with no headings renders {{toc}} as an empty result rather than an error', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("{{toc}}\n\nJust text, no headings.\n", $project);

    expect($html)->toContain('Just text, no headings.');
});

test('the toc renders on the wiki show page for a member who can view it', function () {
    $project = Project::factory()->create();
    $user = wikiTocMember($project);
    $page = \App\Models\WikiPage::factory()->for($project)->create();
    $page->versions()->create([
        'author_id' => $user->id,
        'text' => "# Overview\n\n{{toc}}\n\n## Details\n\nbody\n",
        'version' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSeeHtml('table-of-contents')
        ->assertSeeHtml('#details');
});
