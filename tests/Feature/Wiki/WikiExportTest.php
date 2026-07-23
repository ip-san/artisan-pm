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

/**
 * @return array<string, string> entry name => contents
 */
function zipEntries(string $zipContent): array
{
    $path = tempnam(sys_get_temp_dir(), 'wiki-export-test');
    file_put_contents($path, $zipContent);

    $zip = new ZipArchive;
    $zip->open($path);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entries[$name] = $zip->getFromName($name);
    }

    $zip->close();
    unlink($path);

    return $entries;
}

test('a member with export_wiki_pages can export the whole wiki as a zip of txt files', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $first = WikiPage::factory()->for($project)->create(['title' => 'First Page']);
    $first->versions()->create(['author_id' => $user->id, 'text' => 'First content', 'version' => 2]);
    $second = WikiPage::factory()->for($project)->create(['title' => 'Second Page']);
    $second->versions()->create(['author_id' => $user->id, 'text' => 'Second content', 'version' => 2]);

    $component = Livewire::actingAs($user)
        ->test('wiki.index', ['project' => $project])
        ->call('exportZip', 'txt')
        ->assertFileDownloaded("{$project->identifier}-wiki-txt.zip");

    $entries = zipEntries(base64_decode($component->effects['download']['content']));

    expect($entries)->toBe([
        'First Page.txt' => 'First content',
        'Second Page.txt' => 'Second content',
    ]);
});

test('a member with export_wiki_pages can export the whole wiki as a zip of html files', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'My Page']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'Some **markdown** text.', 'version' => 2]);

    $renderedBody = app(WikiMarkdownRenderer::class)->render('Some **markdown** text.', $project, $page->attachments());

    $component = Livewire::actingAs($user)
        ->test('wiki.index', ['project' => $project])
        ->call('exportZip', 'html')
        ->assertFileDownloaded("{$project->identifier}-wiki-html.zip");

    $entries = zipEntries(base64_decode($component->effects['download']['content']));

    expect($entries)->toBe([
        'My Page.html' => <<<HTML
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
            HTML,
    ]);
});

test('a member without export_wiki_pages cannot export the whole wiki and does not see the links', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project, ['view_wiki_pages']);
    WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.index', ['project' => $project])
        ->assertDontSee('ZIP(TXT)')
        ->assertDontSee('ZIP(HTML)')
        ->call('exportZip', 'txt')
        ->assertForbidden();
});

test('exportZip rejects an unrecognized format', function () {
    $project = Project::factory()->create();
    $user = wikiExportMember($project);

    Livewire::actingAs($user)
        ->test('wiki.index', ['project' => $project])
        ->call('exportZip', 'pdf')
        ->assertNotFound();
});
