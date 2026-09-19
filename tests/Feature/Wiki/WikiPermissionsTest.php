<?php

use App\Events\WikiPageDeleted;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function wikiPermissionMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function wikiPermissionPage(Project $project, string $title = 'Start'): WikiPage
{
    $page = WikiPage::factory()->for($project)->create(['title' => $title]);
    $page->versions()->create(['author_id' => User::factory()->create()->id, 'text' => 'body', 'version' => 2]);

    return $page;
}

test('history, versions, diffs and annotate need view_wiki_edits', function () {
    $project = Project::factory()->create();
    $reader = wikiPermissionMember($project, ['view_project', 'view_wiki_pages']);
    $auditor = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'view_wiki_edits']);
    $page = wikiPermissionPage($project);

    Livewire::actingAs($reader)->test('wiki.history', ['project' => $project, 'wikiPage' => $page])->assertForbidden();
    Livewire::actingAs($reader)->test('wiki.version', ['project' => $project, 'wikiPage' => $page, 'version' => 1])->assertForbidden();
    Livewire::actingAs($reader)->test('wiki.annotate', ['project' => $project, 'wikiPage' => $page, 'version' => 1])->assertForbidden();
    Livewire::actingAs($reader)->test('wiki.diff', ['project' => $project, 'wikiPage' => $page, 'from' => 1, 'to' => 2])->assertForbidden();
    Livewire::actingAs($reader)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->assertOk()->assertDontSee('履歴');

    Livewire::actingAs($auditor)->test('wiki.history', ['project' => $project, 'wikiPage' => $page])->assertOk();
    Livewire::actingAs($auditor)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->assertSee('履歴');
});

test('viewing an older version through the API needs view_wiki_edits', function () {
    $project = Project::factory()->create();
    $reader = wikiPermissionMember($project, ['view_project', 'view_wiki_pages']);
    $auditor = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'view_wiki_edits']);
    $page = wikiPermissionPage($project);

    Passport::actingAs($reader);
    $this->getJson("/api/v1/wiki/{$page->id}")->assertOk();
    $this->getJson("/api/v1/wiki/{$page->id}?version=1")->assertForbidden();

    Passport::actingAs($auditor);
    $this->getJson("/api/v1/wiki/{$page->id}?version=1")->assertOk();
});

test('wiki edits only appear in the activity of someone with view_wiki_edits', function () {
    $project = Project::factory()->create();
    $reader = wikiPermissionMember($project, ['view_project', 'view_wiki_pages']);
    $auditor = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'view_wiki_edits']);
    wikiPermissionPage($project);

    $entries = fn (User $user) => Livewire::actingAs($user)->test('activity.index', ['project' => $project])
        ->set('activeTypes', ['wiki-edit'])
        ->get('entries');

    expect($entries($reader))->toHaveCount(0)
        ->and($entries($auditor)->count())->toBeGreaterThan(0);
});

test('deleting a wiki page attachment needs delete_wiki_pages_attachments', function () {
    Storage::fake('local');
    $project = Project::factory()->create();
    $editor = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'edit_wiki_pages']);
    $cleaner = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages_attachments']);
    $page = wikiPermissionPage($project);
    $page->addMediaFromString('x')->usingFileName('a.txt')->toMediaCollection('attachments');
    $mediaId = $page->attachments()->first()->id;

    Livewire::actingAs($editor)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->call('deleteAttachment', $mediaId)->assertForbidden();
    expect($page->fresh()->attachments())->toHaveCount(1);

    Livewire::actingAs($cleaner)->test('wiki.show', ['project' => $project, 'wikiPage' => $page])->call('deleteAttachment', $mediaId);
    expect($page->fresh()->attachments())->toHaveCount(0);
});

test('a member with manage_wiki can delete the whole wiki, and nobody else can', function () {
    Event::fake([WikiPageDeleted::class]);
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $manager = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'manage_wiki']);
    $deleter = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'delete_wiki_pages']);
    $root = wikiPermissionPage($project, 'Start');
    WikiPage::factory()->for($project)->create(['title' => 'Child', 'parent_id' => $root->id]);
    $untouched = wikiPermissionPage($other, 'Elsewhere');

    Livewire::actingAs($deleter)->test('wiki.pages', ['project' => $project])->call('deleteWiki')->assertForbidden();
    expect($project->wikiPages()->count())->toBe(2);

    Livewire::actingAs($manager)->test('wiki.pages', ['project' => $project])
        ->assertSee('Wikiを削除')
        ->call('deleteWiki')
        ->assertRedirect(route('projects.show', $project));

    expect($project->wikiPages()->count())->toBe(0)
        ->and($untouched->fresh())->not->toBeNull();
    Event::assertDispatched(WikiPageDeleted::class, 2);
});

test('the delete button is hidden without manage_wiki', function () {
    $project = Project::factory()->create();
    $deleter = wikiPermissionMember($project, ['view_project', 'view_wiki_pages', 'delete_wiki_pages']);
    wikiPermissionPage($project);

    Livewire::actingAs($deleter)->test('wiki.pages', ['project' => $project])->assertDontSee('Wikiを削除');
});
