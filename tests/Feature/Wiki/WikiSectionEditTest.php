<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiSectionEditMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function wikiSectionEditPage(Project $project, User $author): WikiPage
{
    $page = WikiPage::factory()->for($project)->create();

    app(WikiPageService::class)->update($page, [], <<<'MD'
        # Section One

        Original content one.

        # Section Two

        Original content two.
        MD, $author);

    return $page->fresh();
}

test('an editor sees numbered section-edit links on each heading', function () {
    $project = Project::factory()->create();
    $editor = wikiSectionEditMember($project);
    $page = wikiSectionEditPage($project, $editor);

    Livewire::actingAs($editor)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertSeeHtml('?section=1')
        ->assertSeeHtml('?section=2');
});

test('a viewer without update permission does not see section-edit links', function () {
    $project = Project::factory()->create();
    $editor = wikiSectionEditMember($project);
    $viewer = wikiSectionEditMember($project, ['view_wiki_pages']);
    $page = wikiSectionEditPage($project, $editor);

    Livewire::actingAs($viewer)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->assertDontSeeHtml('?section=1');
});

test('a ?section= query string prefills the form with just that section\'s text', function () {
    $project = Project::factory()->create();
    $editor = wikiSectionEditMember($project);
    $page = wikiSectionEditPage($project, $editor);

    $component = Livewire::actingAs($editor)
        ->withQueryParams(['section' => 2])
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page]);

    expect($component->get('text'))->toBe("# Section Two\n\nOriginal content two.")
        ->and($component->get('sectionIndex'))->toBe(2);
});

test('saving a section splices it back into the page, leaving the rest untouched', function () {
    $project = Project::factory()->create();
    $editor = wikiSectionEditMember($project);
    $page = wikiSectionEditPage($project, $editor);

    Livewire::actingAs($editor)
        ->withQueryParams(['section' => 1])
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('text', "# Section One\n\nEdited content one.")
        ->call('save')
        ->assertHasNoErrors();

    $newText = $page->fresh()->currentVersion->text;

    expect($newText)->toContain('Edited content one.')
        ->not->toContain('Original content one.')
        ->and($newText)->toContain('Section Two')
        ->toContain('Original content two.');
});

test('saving a section that changed concurrently is rejected as stale', function () {
    $project = Project::factory()->create();
    $editor = wikiSectionEditMember($project);
    $other = wikiSectionEditMember($project);
    $page = wikiSectionEditPage($project, $editor);

    $component = Livewire::actingAs($editor)
        ->withQueryParams(['section' => 2])
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page]);

    app(WikiPageService::class)->update($page, [], <<<'MD'
        # Section One

        Original content one.

        # Section Two

        Someone else already changed this.
        MD, $other);

    $component->set('text', "# Section Two\n\nMy conflicting edit.")
        ->call('save')
        ->assertHasErrors(['text']);

    expect($page->fresh()->currentVersion->text)->toContain('Someone else already changed this.')
        ->not->toContain('My conflicting edit.');
});
