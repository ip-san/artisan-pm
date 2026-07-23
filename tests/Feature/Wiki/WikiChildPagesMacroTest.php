<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function wikiChildPagesMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages', 'edit_wiki_pages']])
    );

    return $user;
}

test('a {{child_pages}} line on its own is replaced with a list of the page\'s children, alphabetically', function () {
    $project = Project::factory()->create();
    $parent = WikiPage::factory()->for($project)->create(['title' => 'Parent']);
    $childB = WikiPage::factory()->for($project)->for($parent, 'parent')->create(['title' => 'Zeta']);
    $childA = WikiPage::factory()->for($project)->for($parent, 'parent')->create(['title' => 'Alpha']);

    $html = app(WikiMarkdownRenderer::class)->render("# Parent\n\n{{child_pages}}\n", $project, page: $parent);

    expect($html)->toContain('<ul class="child-pages">');

    $alphaPosition = strpos($html, $childA->title);
    $zetaPosition = strpos($html, $childB->title);
    expect($alphaPosition)->not->toBeFalse()
        ->and($zetaPosition)->not->toBeFalse()
        ->and($alphaPosition)->toBeLessThan($zetaPosition)
        ->and($html)->toContain('href="'.route('wiki.show', [$project, $childA]).'"')
        ->and($html)->toContain('href="'.route('wiki.show', [$project, $childB]).'"');
});

test('a page with no children renders {{child_pages}} as an empty result rather than an error', function () {
    $project = Project::factory()->create();
    $page = WikiPage::factory()->for($project)->create();

    $html = app(WikiMarkdownRenderer::class)->render("{{child_pages}}\n\nNo children here.\n", $project, page: $page);

    expect($html)->toContain('No children here.')
        ->and($html)->not->toContain('{{child_pages}}');
});

test('{{child_pages}} is left as literal text when there is no page in scope', function () {
    $project = Project::factory()->create();

    $html = app(WikiMarkdownRenderer::class)->render("{{child_pages}}\n", $project);

    expect($html)->toContain('{{child_pages}}');
});

test('{{child_pages}} text elsewhere in a line (not alone) is left as plain text', function () {
    $project = Project::factory()->create();
    $page = WikiPage::factory()->for($project)->create();
    WikiPage::factory()->for($project)->for($page, 'parent')->create(['title' => 'Should not appear']);

    $html = app(WikiMarkdownRenderer::class)->render("See {{child_pages}} in the docs.\n", $project, page: $page);

    expect($html)->toContain('{{child_pages}}')
        ->and($html)->not->toContain('child-pages');
});

test('child_pages renders on the wiki show page for a member who can view it', function () {
    $project = Project::factory()->create();
    $user = wikiChildPagesMember($project);
    $parent = WikiPage::factory()->for($project)->create();
    $child = WikiPage::factory()->for($project)->for($parent, 'parent')->create(['title' => 'Child Page']);
    $parent->versions()->create([
        'author_id' => $user->id,
        'text' => "# Overview\n\n{{child_pages}}\n",
        'version' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $parent])
        ->assertSeeHtml('child-pages')
        ->assertSee('Child Page')
        ->assertSeeHtml(route('wiki.show', [$project, $child]));
});
