<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiVersionMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('an old version link is shown to editors on the version view', function () {
    $project = Project::factory()->create();
    $editor = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $editor);

    Livewire::actingAs($editor)
        ->test('wiki.version', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->assertSee('このバージョンを復元');
});

test('a viewer without edit_wiki_pages does not see the restore link', function () {
    $project = Project::factory()->create();
    $editor = wikiVersionMember($project);
    $viewer = wikiVersionMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $editor);

    Livewire::actingAs($viewer)
        ->test('wiki.version', ['project' => $project, 'wikiPage' => $page, 'version' => 1])
        ->assertDontSee('このバージョンを復元');
});

test('editing with a ?version= query string prefills the old text, and saving appends a new version', function () {
    $project = Project::factory()->create();
    $user = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();
    $originalText = $page->currentVersion->text;
    app(WikiPageService::class)->update($page, [], 'second version text', $user);

    $component = Livewire::actingAs($user)
        ->withQueryParams(['version' => 1])
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page]);

    expect($component->get('text'))->toBe($originalText);

    $component->call('save');

    $page->refresh();

    expect($page->versions)->toHaveCount(3)
        ->and($page->currentVersion->version)->toBe(3)
        ->and($page->currentVersion->text)->toBe($originalText);
});

test('a nonexistent ?version= falls back to the current text', function () {
    $project = Project::factory()->create();
    $user = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();

    $component = Livewire::actingAs($user)
        ->withQueryParams(['version' => 999])
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page]);

    expect($component->get('text'))->toBe($page->currentVersion->text);
});

test('an editor can delete an old, non-current version', function () {
    $project = Project::factory()->create();
    $user = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $user);

    $oldVersion = $page->versions()->where('version', 1)->firstOrFail();

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->call('deleteVersion', $oldVersion->id);

    expect($page->fresh()->versions)->toHaveCount(1)
        ->and($page->fresh()->versions()->where('version', 1)->exists())->toBeFalse();
});

test('the current version cannot be deleted', function () {
    $project = Project::factory()->create();
    $user = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $user);

    $currentVersion = $page->currentVersion;

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->call('deleteVersion', $currentVersion->id)
        ->assertForbidden();

    expect($page->fresh()->versions)->toHaveCount(2);
});

test('the only remaining version cannot be deleted', function () {
    $project = Project::factory()->create();
    $user = wikiVersionMember($project);
    $page = WikiPage::factory()->for($project)->create();
    $onlyVersion = $page->currentVersion;

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->call('deleteVersion', $onlyVersion->id)
        ->assertForbidden();

    expect($page->fresh()->versions)->toHaveCount(1);
});

test('a user without edit_wiki_pages cannot delete a version', function () {
    $project = Project::factory()->create();
    $editor = wikiVersionMember($project);
    $viewer = wikiVersionMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $editor);

    $oldVersion = $page->versions()->where('version', 1)->firstOrFail();

    Livewire::actingAs($viewer)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->call('deleteVersion', $oldVersion->id)
        ->assertForbidden();

    expect($page->fresh()->versions)->toHaveCount(2);
});
