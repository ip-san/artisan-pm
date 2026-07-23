<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function wikiCollapseMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages', 'edit_wiki_pages']])
    );

    return $user;
}

test('a {{collapse}} block renders as a details/summary element with the default label', function () {
    $project = Project::factory()->create();
    $text = "{{collapse\nHidden content here.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<details>')
        ->and($html)->toContain('<summary>表示</summary>')
        ->and($html)->toContain('Hidden content here.')
        ->and($html)->toContain('</details>');
});

test('{{collapse(Label)}} uses the given label instead of the default', function () {
    $project = Project::factory()->create();
    $text = "{{collapse(View details)\nSome text.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<summary>View details</summary>');
});

test('the collapsed body is itself rendered as markdown', function () {
    $project = Project::factory()->create();
    $text = "{{collapse\nThis is **bold** text.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<strong>bold</strong>');
});

test('content outside a collapse block still renders normally', function () {
    $project = Project::factory()->create();
    $text = "# Heading\n\n{{collapse\nHidden.\n}}\n\nAfter the block.\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<h1')
        ->and($html)->toContain('Heading')
        ->and($html)->toContain('After the block.');
});

test('an unclosed collapse block is left as literal text', function () {
    $project = Project::factory()->create();
    $text = "{{collapse\nNever closed.\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('{{collapse')
        ->and($html)->not->toContain('<details>');
});

test('a collapse block nested inside another degrades safely rather than nesting correctly', function () {
    // Documented limitation: the non-greedy body match closes on the
    // first `}}` it finds, which for nested blocks is the inner one's —
    // so the outer block's body ends up truncated there, and the
    // leftover text (including the now-orphaned inner opening tag) is
    // left as literal text rather than the page crashing or losing
    // content entirely.
    $project = Project::factory()->create();
    $text = "{{collapse(Outer)\nBefore.\n\n{{collapse(Inner)\nNested content.\n}}\n\nAfter.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<summary>Outer</summary>')
        ->and($html)->toContain('Before.')
        ->and($html)->toContain('Nested content.')
        ->and(substr_count($html, '<details>'))->toBe(1)
        ->and($html)->toContain('After.');
});

test('the collapse macro renders on the wiki show page for a member who can view it', function () {
    $project = Project::factory()->create();
    $user = wikiCollapseMember($project);
    $page = WikiPage::factory()->for($project)->create();
    $page->versions()->create([
        'author_id' => $user->id,
        'text' => "# Overview\n\n{{collapse(Extra info)\nSome extra info.\n}}\n",
        'version' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSeeHtml('<summary>Extra info</summary>')
        ->assertSee('Some extra info.');
});
