<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Support\Markdown\WikiMarkdownRenderer;
use Livewire\Livewire;

function wikiExportMember(Project $project, array $permissions = ['view_wiki_pages', 'export_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with export_wiki_pages can export a page as txt', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'My Page']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'Some **markdown** text.', 'version' => 2]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportTxt')
        ->assertFileDownloaded('My Page.txt', 'Some **markdown** text.');
});

test('a member with export_wiki_pages can export a page as html', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'My Page']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'Some **markdown** text.', 'version' => 2]);

    $response = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportHtml');

    $response->assertFileDownloaded('My Page.html');
});

test('exported html renders the markdown content and page title', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'My Page']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'Some **markdown** text.', 'version' => 2]);

    $renderedBody = app(WikiMarkdownRenderer::class)->render('Some **markdown** text.', $project, $page->attachments());

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportHtml')
        ->assertFileDownloaded('My Page.html', <<<HTML
            <!DOCTYPE html>
            <html lang="ja">
            <head>
            <meta charset="UTF-8">
            <title>My Page</title>
            </head>
            <body>
            <h1>My Page</h1>
            {$renderedBody}
            </body>
            </html>
            HTML);
});

test('a member without export_wiki_pages cannot export a page', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportTxt')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportHtml')
        ->assertForbidden();
});

test('a member without export_wiki_pages does not see the export links', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSee('TXT')
        ->assertDontSee('HTML');
});

test('a slash in the page title is sanitized out of the export filename', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Parent/Child']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'text', 'version' => 2]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('exportTxt')
        ->assertFileDownloaded('Parent-Child.txt');
});
