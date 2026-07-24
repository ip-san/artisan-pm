<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiPage;
use Laravel\Passport\Passport;

function apiWikiPageMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $this->getJson("/api/v1/projects/{$project->id}/wiki")->assertUnauthorized();
});

test('a member with view_wiki_pages can list a project\'s wiki pages', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/projects/{$project->id}/wiki");

    $response->assertOk()->assertJsonPath('data.0.id', $page->id)->assertJsonPath('data.0.title', 'Home');
    expect($response->json('data.0'))->not->toHaveKey('text');
});

test('a member without view_wiki_pages cannot list wiki pages', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_issues']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/projects/{$project->id}/wiki")->assertForbidden();
});

test('a member with view_wiki_pages can show a page including its text', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Home']);

    Passport::actingAs($user);

    $this->getJson("/api/v1/wiki/{$page->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Home')
        ->assertJsonPath('data.text', $page->currentVersion->text)
        ->assertJsonPath('data.version', 1);
});

test('requesting a specific version returns that version\'s text', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    $originalText = $page->currentVersion->text;
    $page->versions()->create(['author_id' => $user->id, 'text' => 'Updated text', 'version' => 2]);

    Passport::actingAs($user);

    $this->getJson("/api/v1/wiki/{$page->id}?version=1")
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.text', $originalText);
});

test('requesting a nonexistent version returns 404', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->getJson("/api/v1/wiki/{$page->id}?version=99")->assertNotFound();
});

test('a member with edit_wiki_pages can create a wiki page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/projects/{$project->id}/wiki", [
        'title' => 'New Page',
        'text' => 'Some content',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'New Page')
        ->assertJsonPath('data.text', 'Some content')
        ->assertJsonPath('data.version', 1);
});

test('a member without edit_wiki_pages cannot create a wiki page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/wiki", [
        'title' => 'New Page',
        'text' => 'Some content',
    ])->assertForbidden();
});

test('creating a page with a duplicate title is rejected', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);
    WikiPage::factory()->for($project)->create(['title' => 'Home']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/wiki", [
        'title' => 'Home',
        'text' => 'Some content',
    ])->assertUnprocessable()->assertJsonValidationErrors(['title']);
});

test('a member without protect_wiki_pages cannot protect a page on creation', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/wiki", [
        'title' => 'New Page',
        'text' => 'Some content',
        'is_protected' => true,
    ])->assertCreated()->assertJsonPath('data.is_protected', false);
});

test('a member with protect_wiki_pages can protect a page on creation', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages', 'protect_wiki_pages']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/projects/{$project->id}/wiki", [
        'title' => 'New Page',
        'text' => 'Some content',
        'is_protected' => true,
    ])->assertCreated()->assertJsonPath('data.is_protected', true);
});

test('updating the text creates a new version', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['text' => 'Revised content'])
        ->assertOk()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.text', 'Revised content');

    expect($page->versions()->count())->toBe(2);
});

test('updating with unchanged text does not create a new version', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();
    $originalText = $page->currentVersion->text;

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['text' => $originalText, 'comments' => 'typo fix'])
        ->assertOk()
        ->assertJsonPath('data.version', 1);

    expect($page->versions()->count())->toBe(1);
});

test('a member without rename_wiki_pages cannot rename a page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Original']);

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['title' => 'Renamed'])->assertOk();

    expect($page->fresh()->title)->toBe('Original');
});

test('a member with rename_wiki_pages can rename a page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages', 'rename_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Original']);

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed');
});

test('setting a page\'s parent to one of its own descendants is rejected', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages', 'rename_wiki_pages']);
    $root = WikiPage::factory()->for($project)->create();
    $child = WikiPage::factory()->for($project)->create(['parent_id' => $root->id]);

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$root->id}", ['parent_id' => $child->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});

test('a member without edit_wiki_pages cannot update a page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['text' => 'Hacked'])->assertForbidden();
});

test('a protected page cannot be updated without protect_wiki_pages', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['edit_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['is_protected' => true]);

    Passport::actingAs($user);

    $this->putJson("/api/v1/wiki/{$page->id}", ['text' => 'Should be blocked'])->assertForbidden();
});

test('a member with delete_wiki_pages can delete a page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['delete_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/wiki/{$page->id}")->assertNoContent();

    expect(WikiPage::find($page->id))->toBeNull();
});

test('a member without delete_wiki_pages cannot delete a page', function () {
    $project = Project::factory()->create();
    $user = apiWikiPageMember($project, ['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/wiki/{$page->id}")->assertForbidden();

    expect(WikiPage::find($page->id))->not->toBeNull();
});
