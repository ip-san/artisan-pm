<?php

use App\Enums\RoleBuiltin;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Http\UploadedFile;

function grantAnonymousAccess(array $permissions): void
{
    Role::factory()->create([
        'builtin' => RoleBuiltin::Anonymous->value,
        'permissions' => $permissions,
    ]);
}

test('a guest is redirected to login for the issue list when login_required is left at its default', function () {
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_issues']);

    $this->get(route('issues.index', $project))->assertRedirect(route('login'));
});

test('a guest can view a public project\'s issue list once login_required is disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_issues']);
    $issue = Issue::factory()->for($project)->create();

    $this->get(route('issues.index', $project))->assertOk();
    $this->get(route('issues.show', [$project, $issue]))->assertOk();
});

test('a guest can view a densely-populated public issue without crashing on any null-user render path', function () {
    // The initial implementation crashed on exactly this shape of data:
    // isWatchedBy(auth()->user()) with no @can guard, and
    // visibleJournals() calling ->can() directly on a null $user. An
    // issue with no journals/relations/attachments never exercised those
    // branches, so this fixture is deliberately thicker than the bare
    // one above.
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_issues']);
    $author = User::factory()->create();
    $issue = Issue::factory()->for($project)->create(['author_id' => $author->id]);
    $otherIssue = Issue::factory()->for($project)->create();

    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => '公開コメント', 'private_notes' => false]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => '非公開コメント', 'private_notes' => true]);
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $otherIssue->id, 'relation_type' => 'relates']);
    $issue->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $response = $this->get(route('issues.show', [$project, $issue]));

    $response->assertOk();
    // A guest never has an "own" identity, so a private note must never
    // leak into the guest-visible response body.
    $response->assertDontSee('非公開コメント');
    $response->assertSee('公開コメント');
});

test('a guest can view a public issue\'s attachment and its thumbnail once login_required is disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    $this->get(route('attachments.show', $media))->assertOk();
    $this->get(route('attachments.thumb', $media))->assertOk();
});

test('a guest still cannot fetch a private project\'s attachment even once login_required is disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->private()->create();
    grantAnonymousAccess(['view_issues']);
    $issue = Issue::factory()->for($project)->create();
    $media = $issue->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');

    $this->get(route('attachments.show', $media))->assertForbidden();
    $this->get(route('attachments.thumb', $media))->assertForbidden();
});

test('a guest still cannot view a private project\'s issues once login_required is disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->private()->create();
    grantAnonymousAccess(['view_issues']);
    $issue = Issue::factory()->for($project)->create();

    $this->get(route('issues.index', $project))->assertForbidden();
    $this->get(route('issues.show', [$project, $issue]))->assertForbidden();
});

test('a guest can view a public project\'s wiki once login_required is disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create();

    $this->get(route('wiki.pages', $project))->assertOk();
    $this->get(route('wiki.date-index', $project))->assertOk();
    $this->get(route('wiki.show', [$project, $page]))->assertOk();
});

test('a guest is redirected from the bare wiki URL to the start page, not bounced to login', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_wiki_pages']);
    $startPage = WikiPage::factory()->for($project)->create(['title' => 'Wiki']);

    $this->get(route('wiki.index', $project))
        ->assertRedirect(route('wiki.show', [$project, $startPage]));
});

test('a guest hitting the bare wiki URL on a wiki with no start page yet still ends up at a login wall', function () {
    // A known, accepted edge case: wiki.index redirects a guest to
    // wiki.create (prefilled with the start page's title) exactly like it
    // would for an authenticated user, but wiki.create itself isn't in
    // the guest-eligible route list (creating pages was never part of the
    // "課題一覧・Wiki閲覧" scope this feature opened up) — so a guest ends
    // up bounced to login on that second hop, on an otherwise-viewable
    // public project, rather than a clean 403. Pinned here rather than
    // left as an untested assumption.
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_wiki_pages']);

    $redirectToCreate = $this->get(route('wiki.index', $project));
    $redirectToCreate->assertRedirect(route('wiki.create', $project).'?title=Wiki');

    $this->get($redirectToCreate->headers->get('Location'))->assertRedirect(route('login'));
});

test('a guest can view a wiki page with real content and an inline attachment image without crashing', function () {
    // A page with no currentVersion never exercises the Markdown render
    // path at all (renderedContent() short-circuits on `?? ''`) — this
    // fixture gives it a real version with an inline attachment:file.png
    // reference, so resolveInlineAttachmentImages() actually runs for a
    // guest request.
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_wiki_pages']);
    $author = User::factory()->create();
    $page = WikiPage::factory()->for($project)->create();
    $page->addMedia(UploadedFile::fake()->image('screenshot.png'))->toMediaCollection('attachments');
    $page->versions()->create([
        'author_id' => $author->id,
        'text' => "本文です。\n\n![img](screenshot.png)",
        'version' => 2,
    ]);

    $response = $this->get(route('wiki.show', [$project, $page]));

    $response->assertOk();
    $response->assertSee(route('attachments.show', $page->attachments()->first()), escape: false);
});

test('a guest without the anonymous role\'s view permission is still forbidden even with login_required disabled', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    // No Anonymous role created at all — AuthorizationService::rolesFor()
    // returns an empty collection for a guest with none registered.
    $page = WikiPage::factory()->for($project)->create();

    $this->get(route('wiki.show', [$project, $page]))->assertForbidden();
});

test('routes outside the guest-eligible list still require login regardless of login_required', function () {
    Setting::set('login_required', false);
    $project = Project::factory()->create();
    grantAnonymousAccess(['view_project', 'view_issues', 'view_wiki_pages']);

    $this->get(route('projects.index'))->assertRedirect(route('login'));
    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
    $this->get(route('my-page.index'))->assertRedirect(route('login'));
});

test('an authenticated project member is unaffected by login_required either way', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    Setting::set('login_required', true);
    $this->actingAs($user)->get(route('issues.index', $project))->assertOk();

    Setting::set('login_required', false);
    $this->actingAs($user)->get(route('issues.index', $project))->assertOk();
});
