<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
use App\Services\WikiPageService;
use Livewire\Livewire;

function wikiMoveMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

test('moveTargetProjects only lists projects where the user also holds rename_wiki_pages', function () {
    $project = Project::factory()->create();
    $reachable = Project::factory()->create();
    $unreachable = Project::factory()->create();
    $user = wikiMoveMember($project, ['view_wiki_pages', 'rename_wiki_pages']);
    Member::factory()->for($reachable)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['rename_wiki_pages']])
    );
    Member::factory()->for($unreachable)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_wiki_pages']])
    );
    $page = WikiPage::factory()->for($project)->create();

    $targetIds = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->get('moveTargetProjects')
        ->pluck('id');

    expect($targetIds)->toContain($reachable->id)
        ->not->toContain($unreachable->id)
        ->not->toContain($project->id);
});

test('moving a page to another project updates its project and clears its parent', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $user = wikiMoveMember($project, ['view_wiki_pages', 'rename_wiki_pages']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['rename_wiki_pages']])
    );
    $parent = WikiPage::factory()->for($project)->create();
    $page = WikiPage::factory()->for($project)->create(['title' => 'Movable Page', 'parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('moveToProjectId', $target->id)
        ->call('moveToProject')
        ->assertHasNoErrors();

    $page->refresh();

    expect($page->project_id)->toBe($target->id)
        ->and($page->parent_id)->toBeNull();
});

test('moving a page detaches its own children rather than cascading the move', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $user = wikiMoveMember($project, ['view_wiki_pages', 'rename_wiki_pages']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['rename_wiki_pages']])
    );
    $page = WikiPage::factory()->for($project)->create();
    $child = WikiPage::factory()->for($project)->create(['parent_id' => $page->id]);

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('moveToProjectId', $target->id)
        ->call('moveToProject');

    expect($child->fresh()->project_id)->toBe($project->id)
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('a member without rename_wiki_pages in the destination cannot move a page there', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $user = wikiMoveMember($project, ['view_wiki_pages', 'rename_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('moveToProjectId', $target->id)
        ->call('moveToProject')
        ->assertHasErrors(['moveToProjectId']);

    expect($page->fresh()->project_id)->toBe($project->id);
});

test('a member without rename_wiki_pages in the source project cannot move a page', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $user = wikiMoveMember($project, ['view_wiki_pages', 'edit_wiki_pages']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['rename_wiki_pages']])
    );
    $page = WikiPage::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $page])
        ->set('moveToProjectId', $target->id)
        ->call('moveToProject')
        ->assertForbidden();

    expect($page->fresh()->project_id)->toBe($project->id);
});

test('moving a page leaves a cross-project redirect behind at the old title', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Moved Page'], 'text', $author);

    app(WikiPageService::class)->moveToProject($page, $target);

    $redirect = WikiRedirect::where('project_id', $project->id)->where('title', 'Moved Page')->first();

    expect($redirect)->not->toBeNull()
        ->and($redirect->redirects_to)->toBe('Moved Page')
        ->and($redirect->redirects_to_project_id)->toBe($target->id);
});

test('a [[Title]] link in the old project resolves cross-project to the moved page', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Moved Page'], 'text', $author);
    app(WikiPageService::class)->moveToProject($page, $target);

    $referrer = app(WikiPageService::class)->create($project, ['title' => 'Referrer'], 'See [[Moved Page]] for details.', $author);

    $user = wikiMoveMember($project, ['view_wiki_pages']);

    $html = Livewire::actingAs($user)
        ->test('wiki.show', ['project' => $project, 'wikiPage' => $referrer])
        ->get('renderedContent');

    expect($html)->toContain(route('wiki.show', [$target, $page->fresh()]));
});

test('deleting a moved page removes the cross-project redirect left behind', function () {
    $project = Project::factory()->create();
    $target = Project::factory()->create();
    $author = User::factory()->create();
    $page = app(WikiPageService::class)->create($project, ['title' => 'Moved Page'], 'text', $author);
    app(WikiPageService::class)->moveToProject($page, $target);

    expect(WikiRedirect::where('redirects_to_project_id', $target->id)->count())->toBe(1);

    app(WikiPageService::class)->delete($page->fresh());

    expect(WikiRedirect::where('redirects_to_project_id', $target->id)->count())->toBe(0);
});
