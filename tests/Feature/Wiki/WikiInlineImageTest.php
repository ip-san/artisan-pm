<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\WikiPageService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function wikiInlineImageMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages']])
    );

    return $user;
}

test('a bare-filename image reference to an attached file resolves inline', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See ![](screenshot.png) below.', $author);
    $media = $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('renderedContent');

    expect($html)->toContain(route('attachments.show', $media));
});

test('the filename match is case-insensitive', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See ![](Screenshot.PNG) below.', $author);
    $media = $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('renderedContent');

    expect($html)->toContain(route('attachments.show', $media));
});

test('an image reference with no matching attachment is left unresolved', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See ![](missing.png) below.', $author);

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('renderedContent');

    expect($html)->toContain('src="missing.png"');
});

test('a full URL image target is left untouched even if an attachment shares its filename', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See ![](https://example.com/screenshot.png) below.', $author);
    $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('renderedContent');

    expect($html)->toContain('src="https://example.com/screenshot.png"');
});

test('a non-image attachment with a matching name is not treated as an inline image', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See [report.pdf](report.pdf) below.', $author);
    $page->addMedia(UploadedFile::fake()->create('report.pdf', 100))->toMediaCollection('attachments');

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('renderedContent');

    expect($html)->toContain('href="report.pdf"');
});

test('an old version view also resolves inline images against the page\'s current attachments', function () {
    $project = Project::factory()->create();
    $user = wikiInlineImageMember($project);
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Home'], 'See ![](screenshot.png) below.', $author);
    $media = $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');
    app(WikiPageService::class)->update($page, [], 'second version, still ![](screenshot.png)', $author);

    $html = Livewire::actingAs($user)
        ->test('wiki.version', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->get('renderedContent');

    expect($html)->toContain(route('attachments.show', $media));
});
