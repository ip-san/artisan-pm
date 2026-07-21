<?php

use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with view_wiki_pages can see the wiki index and a page', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)->test('wiki.index', ['project' => $project])->assertOk();
    Livewire::actingAs($user)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->assertOk();
});

test('a member without view_wiki_pages is forbidden from the wiki', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, []);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)->test('wiki.index', ['project' => $project])->assertForbidden();
    Livewire::actingAs($user)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->assertForbidden();
});

test('a member with edit_wiki_pages can create a wiki page, starting at version 1', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project])
        ->set('title', 'Getting Started')
        ->set('text', "# Hello\n\nWelcome.")
        ->call('save');

    $page = WikiPage::where('title', 'Getting Started')->firstOrFail();

    expect($page->currentVersion->version)->toBe(1)
        ->and($page->currentVersion->text)->toBe("# Hello\n\nWelcome.")
        ->and($page->currentVersion->author_id)->toBe($user->id);
});

test('editing a page with changed text appends a new version', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('text', 'Updated content')
        ->set('comments', 'clarified wording')
        ->call('save');

    $page->refresh();

    expect($page->versions)->toHaveCount(2)
        ->and($page->currentVersion->version)->toBe(2)
        ->and($page->currentVersion->text)->toBe('Updated content')
        ->and($page->currentVersion->comments)->toBe('clarified wording');
});

test('saving a page with unchanged text does not create a redundant version', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);
    $originalText = $page->currentVersion->text;

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('text', $originalText)
        ->call('save');

    expect($page->fresh()->versions)->toHaveCount(1);
});

test('a member without edit_wiki_pages cannot access the wiki form', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages']);

    Livewire::actingAs($user)->test('wiki.form', ['project' => $project])->assertForbidden();
});

test('a protected page can only be edited by a member with protect_wiki_pages', function () {
    $project = Project::factory()->create();
    $editor = wikiMember($project);
    $protector = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'protect_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['is_protected' => true]);

    Livewire::actingAs($editor)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->assertForbidden();

    Livewire::actingAs($protector)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('text', 'protected edit')
        ->call('save');

    expect($page->fresh()->currentVersion->text)->toBe('protected edit');
});

test('only a member with protect_wiki_pages sees the protect toggle', function () {
    $project = Project::factory()->create();
    $editor = wikiMember($project);
    $protector = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'protect_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($editor)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('toggleProtected')
        ->assertForbidden();

    Livewire::actingAs($protector)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('toggleProtected');

    expect($page->fresh()->is_protected)->toBeTrue();
});

test('a member without rename_wiki_pages cannot change an existing page title', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Original']);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('title', 'Renamed')
        ->set('text', 'new text')
        ->call('save');

    expect($page->fresh()->title)->toBe('Original');
});

test('a member with rename_wiki_pages can rename and reparent a page', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'rename_wiki_pages']);
    $parent = WikiPage::factory()->for($project)->create();
    $page = WikiPage::factory()->for($project)->create(['title' => 'Original']);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('title', 'Renamed')
        ->set('parent_id', $parent->id)
        ->set('text', $page->currentVersion->text)
        ->call('save');

    $page->refresh();

    expect($page->title)->toBe('Renamed')
        ->and($page->parent_id)->toBe($parent->id);
});

test('the parent picker excludes the page itself and its descendants to prevent cycles', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'rename_wiki_pages']);
    $grandparent = WikiPage::factory()->for($project)->create();
    $parent = WikiPage::factory()->for($project)->create(['parent_id' => $grandparent->id]);
    $child = WikiPage::factory()->for($project)->create(['parent_id' => $parent->id]);

    $component = Livewire::actingAs($user)->test('wiki.form', ['project' => $project, 'wikiPage' => $grandparent]);

    $availableIds = $component->get('availableParents')->pluck('id');

    expect($availableIds)->not->toContain($grandparent->id)
        ->not->toContain($parent->id)
        ->not->toContain($child->id);
});

test('a member without delete_wiki_pages cannot delete a page', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('delete')
        ->assertForbidden();

    expect(WikiPage::find($page->id))->not->toBeNull();
});

test('deleting a page moves its children to the top level', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    $child = WikiPage::factory()->for($project)->create(['parent_id' => $page->id]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->call('delete');

    expect(WikiPage::find($page->id))->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('wiki page content renders issue and wiki-link references', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project, ['view_wiki_pages']);
    $target = WikiPage::factory()->for($project)->create(['title' => 'Target Page']);
    $issue = Issue::factory()->for($project)->create();

    $service = app(WikiPageService::class);
    $author = User::factory()->create();
    $page = $service->create($project, ['title' => 'Home'], "See [[Target Page]] and [[Missing Page]] and issue #{$issue->id}.", $author);

    $component = Livewire::actingAs($user)->test('wiki.show', ['project' => $project, 'wikiPage' => $page]);

    $html = $component->get('renderedContent');

    expect($html)->toContain(route('wiki.show', [$project, $target]))
        ->and($html)->toContain(route('wiki.create', $project))
        ->and($html)->toContain(route('issues.show', [$project, $issue]));
});

test('history lists all versions and an old version can be viewed read-only', function () {
    $project = Project::factory()->create();
    $user = wikiMember($project);
    $page = WikiPage::factory()->for($project)->create();
    app(WikiPageService::class)->update($page, [], 'second version text', $user);

    Livewire::actingAs($user)
        ->test('wiki.history', ['project' => $project, 'wikiPage' => $page])
        ->assertOk()
        ->assertSee('v1')
        ->assertSee('v2');

    $component = Livewire::actingAs($user)
        ->test('wiki.version', ['project' => $project, 'wikiPage' => $page, 'version' => 1]);

    expect($component->get('wikiPageVersion')->version)->toBe(1);
});
