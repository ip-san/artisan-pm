<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Wiki;
use App\Models\WikiPage;
use Livewire\Livewire;

function startPageMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages', 'rename_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('wikiOrCreate lazily creates a wiki with the default start_page', function () {
    $project = Project::factory()->create();

    expect($project->wiki)->toBeNull();

    $wiki = $project->wikiOrCreate();

    expect($wiki->start_page)->toBe('Wiki')
        ->and($project->fresh()->wiki)->not->toBeNull();
});

test('wikiOrCreate returns the existing row rather than creating a second one', function () {
    $project = Project::factory()->create();
    $existing = Wiki::factory()->for($project)->create(['start_page' => 'Home']);

    $wiki = $project->wikiOrCreate();

    expect($wiki->id)->toBe($existing->id)
        ->and(Wiki::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('visiting the bare wiki URL redirects to the start page when it exists', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project, ['view_wiki_pages']);
    Wiki::factory()->for($project)->create(['start_page' => 'Home']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);

    $this->actingAs($user)
        ->get(route('wiki.index', $project))
        ->assertRedirect(route('wiki.show', [$project, $page]));
});

test('visiting the bare wiki URL redirects to a prefilled creation form when the start page does not exist yet', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project, ['view_wiki_pages', 'edit_wiki_pages']);

    $this->actingAs($user)
        ->get(route('wiki.index', $project))
        ->assertRedirect(route('wiki.create', $project).'?title=Wiki');
});

test('a member with rename_wiki_pages can set a page as the start page', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'New Home']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'content', 'version' => 2]);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('is_start_page', true)
        ->call('save');

    expect($project->fresh()->wiki->start_page)->toBe('New Home');
});

test('setting a start page while renaming persists the new title, not the old one', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Old Title']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'content', 'version' => 2]);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('title', 'New Title')
        ->set('is_start_page', true)
        ->call('save');

    expect($project->fresh()->wiki->start_page)->toBe('New Title');
});

test('a member without rename_wiki_pages does not see the start-page checkbox', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSee('wire:model="is_start_page"', escape: false);
});

test('unchecking the start-page checkbox on the current start page does not clear it', function () {
    $project = Project::factory()->create();
    $user = startPageMember($project);
    Wiki::factory()->for($project)->create(['start_page' => 'Home']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'content', 'version' => 2]);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->assertSet('is_start_page', true)
        ->set('is_start_page', false)
        ->call('save');

    expect($project->fresh()->wiki->start_page)->toBe('Home');
});
