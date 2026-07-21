<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiRedirectMember(Project $project, array $permissions = ['view_wiki_pages', 'edit_wiki_pages', 'rename_wiki_pages']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('renaming a page creates a redirect from the old title', function () {
    $project = Project::factory()->create();
    $user = wikiRedirectMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('title', 'New Title')
        ->set('text', $page->currentVersion->text)
        ->call('save');

    $redirect = WikiRedirect::where('project_id', $project->id)->where('title', 'Old Title')->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->redirects_to)->toBe('New Title');
});

test('unchecking redirectExistingLinks skips creating a redirect', function () {
    $project = Project::factory()->create();
    $user = wikiRedirectMember($project);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('wiki.form', ['project' => $project, 'wikiPage' => $page])
        ->set('title', 'New Title')
        ->set('text', $page->currentVersion->text)
        ->set('redirectExistingLinks', false)
        ->call('save');

    expect(WikiRedirect::where('project_id', $project->id)->where('title', 'Old Title')->exists())->toBeFalse();
});

test('a [[Old Title]] link resolves to the renamed page via the redirect', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Original'], 'first version', $author);
    app(WikiPageService::class)->update($page, ['title' => 'Renamed'], 'first version', $author);

    $referrer = app(WikiPageService::class)->create($project, ['title' => 'Referrer'], 'See [[Original]] for details.', $author);

    $user = wikiRedirectMember($project, ['view_wiki_pages']);

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $referrer])
        ->get('renderedContent');

    expect($html)->toContain(route('wiki.show', [$project, $page->fresh()]));
});

test('renaming a second time chains the redirect through to the final title', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'A'], 'text', $author);
    app(WikiPageService::class)->update($page, ['title' => 'B'], 'text', $author);
    app(WikiPageService::class)->update($page->fresh(), ['title' => 'C'], 'text', $author);

    $redirectA = WikiRedirect::where('project_id', $project->id)->where('title', 'A')->first();
    $redirectB = WikiRedirect::where('project_id', $project->id)->where('title', 'B')->first();

    expect($redirectA->redirects_to)->toBe('C')
        ->and($redirectB->redirects_to)->toBe('C');
});

test('renaming back to a title that already redirects removes the stale redirect', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'A'], 'text', $author);
    app(WikiPageService::class)->update($page, ['title' => 'B'], 'text', $author);

    expect(WikiRedirect::where('project_id', $project->id)->where('title', 'A')->exists())->toBeTrue();

    app(WikiPageService::class)->update($page->fresh(), ['title' => 'A'], 'text', $author);

    expect(WikiRedirect::where('project_id', $project->id)->where('title', 'A')->exists())->toBeFalse();
});

test('deleting a page removes redirects pointing to it', function () {
    $project = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Original'], 'text', $author);
    app(WikiPageService::class)->update($page, ['title' => 'Renamed'], 'text', $author);

    expect(WikiRedirect::where('project_id', $project->id)->count())->toBe(1);

    $user = wikiRedirectMember($project, ['view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages']);
    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page->fresh()])
        ->call('delete');

    expect(WikiRedirect::where('project_id', $project->id)->count())->toBe(0);
});
