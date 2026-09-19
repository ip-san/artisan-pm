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

test('a collapse block nested inside another renders as a nested details element', function () {
    $project = Project::factory()->create();
    $text = "{{collapse(Outer)\nBefore.\n\n{{collapse(Inner)\nNested content.\n}}\n\nAfter.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect($html)->toContain('<summary>Outer</summary>')
        ->and($html)->toContain('<summary>Inner</summary>')
        ->and(substr_count($html, '<details>'))->toBe(2)
        ->and($html)->toMatch('#<details><summary>Outer</summary>.*Before\..*<details><summary>Inner</summary>.*Nested content\..*</details>.*After\..*</details>#s')
        ->and($html)->not->toContain('{{collapse')
        ->and($html)->not->toContain('COLLAPSE-MACRO-PLACEHOLDER');
});

test('three levels of nesting and sibling blocks after a nested one all render', function () {
    $project = Project::factory()->create();
    $text = "{{collapse(A)\n{{collapse(B)\n{{collapse(C)\nDeep.\n}}\n}}\n}}\n\n{{collapse(Sibling)\nSecond.\n}}\n";

    $html = app(WikiMarkdownRenderer::class)->render($text, $project);

    expect(substr_count($html, '<details>'))->toBe(4)
        ->and($html)->toContain('Deep.')
        ->and($html)->toContain('<summary>Sibling</summary>')
        ->and($html)->not->toContain('{{');
});

test('an unclosed collapse block is left as literal text and nothing else is lost', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("Intro.\n\n{{collapse(Open)\nNever closed.\n", $project);

    expect($html)->toContain('Intro.')
        ->and($html)->toContain('Never closed.')
        ->and($html)->not->toContain('<details>');
});

test('an unclosed inner block does not swallow the outer one', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("{{collapse(Outer)\nText.\n{{collapse(Inner)\nStill open.\n}}\n", $project);

    // Two openings, one closing: the closing pairs with the innermost
    // opening, leaving the outer one unclosed and literal.
    expect(substr_count($html, '<details>'))->toBe(1)
        ->and($html)->toContain('Still open.');
});

test('a collapse block written directly against surrounding text still renders without leaking its placeholder', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("Line before\n{{collapse(Tight)\nInside.\n}}\nLine after\n", $project);

    expect($html)->toContain('<summary>Tight</summary>')
        ->and($html)->toContain('Line before')
        ->and($html)->toContain('Line after')
        ->and($html)->not->toContain('COLLAPSE-MACRO-PLACEHOLDER');
});

test('CRLF line endings still delimit collapse blocks', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("{{collapse(Win)\r\nBody text.\r\n}}\r\n", $project);

    expect($html)->toContain('<summary>Win</summary>')->and($html)->toContain('Body text.');
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
