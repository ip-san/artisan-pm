<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function wikiPreviewMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('the preview is hidden until toggled', function () {
    $project = Project::factory()->create();
    $user = wikiPreviewMember($project);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project])
        ->set('text', 'Hello **world**')
        ->assertSet('showPreview', false)
        ->assertDontSee('<strong>world</strong>', false);
});

test('toggling the preview renders the current text as Markdown', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $user = wikiPreviewMember($project);

    $component = Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project])
        ->set('text', "Hello **world**, see issue #{$issue->id}.")
        ->call('togglePreview');

    $component->assertSet('showPreview', true)
        ->assertSee('<strong>world</strong>', false)
        ->assertSee(route('issues.show', [$project, $issue]), false);
});

test('toggling twice hides the preview again', function () {
    $project = Project::factory()->create();
    $user = wikiPreviewMember($project);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project])
        ->call('togglePreview')
        ->assertSet('showPreview', true)
        ->call('togglePreview')
        ->assertSet('showPreview', false);
});

test('previewing an existing page resolves inline images against its own attachments', function () {
    $project = Project::factory()->create();
    $user = wikiPreviewMember($project);
    $page = WikiPage::factory()->for($project)->create();
    $media = $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('text', 'See ![](screenshot.png) below.')
        ->call('togglePreview')
        ->assertSee(route('attachments.show', $media), false);
});

test('an empty preview shows a placeholder instead of nothing', function () {
    $project = Project::factory()->create();
    $user = wikiPreviewMember($project);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project])
        ->set('text', '')
        ->call('togglePreview')
        ->assertSee('本文が空です');
});
