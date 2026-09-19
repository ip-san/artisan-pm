<?php

use App\Enums\AttachmentSearchMode;
use App\Enums\IssueVisibility;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\SearchService;
use Laravel\Passport\Passport;
use Livewire\Livewire;

function attachmentSearchMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function attachmentSearchIssue(Project $project, string $subject): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => $subject,
        'description' => 'plain body',
    ]);
}

function attachmentSearchAttach(object $owner, string $fileName, ?string $description = null): void
{
    $adder = $owner->addMediaFromString('content')->usingFileName($fileName);

    if ($description !== null) {
        $adder->withCustomProperties(['description' => $description]);
    }

    $adder->toMediaCollection('attachments');
}

/**
 * @return array<int, string>
 */
function attachmentSearchTitles(Project $project, User $viewer, string $query, AttachmentSearchMode $mode, bool $titlesOnly = false): array
{
    return app(SearchService::class)
        ->search($project, $viewer, $query, titlesOnly: $titlesOnly, attachments: $mode)
        ->pluck('title')
        ->all();
}

test('an issue is found through its attachment file name only when attachments are searched', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $issue = attachmentSearchIssue($project, 'Unrelated subject');
    attachmentSearchAttach($issue, 'quarterly-budget.xlsx');

    expect(attachmentSearchTitles($project, $user, 'quarterly-budget', AttachmentSearchMode::Exclude))->toBe([])
        ->and(attachmentSearchTitles($project, $user, 'quarterly-budget', AttachmentSearchMode::Include))->toBe(["#{$issue->id} Unrelated subject"])
        ->and(attachmentSearchTitles($project, $user, 'quarterly-budget', AttachmentSearchMode::Only))->toBe(["#{$issue->id} Unrelated subject"]);
});

test('the attachment description is searched as well', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $issue = attachmentSearchIssue($project, 'Has a described file');
    attachmentSearchAttach($issue, 'scan.pdf', 'signed-contract-copy');

    expect(attachmentSearchTitles($project, $user, 'signed-contract-copy', AttachmentSearchMode::Include))->toHaveCount(1)
        ->and(attachmentSearchTitles($project, $user, 'signed-contract-copy', AttachmentSearchMode::Exclude))->toBe([]);
});

test('include keeps the record own text matches and only mode drops them', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $own = attachmentSearchIssue($project, 'shared-token in the subject');
    $viaFile = attachmentSearchIssue($project, 'Other');
    attachmentSearchAttach($viaFile, 'shared-token.txt');

    expect(collect(attachmentSearchTitles($project, $user, 'shared-token', AttachmentSearchMode::Include))->sort()->values()->all())
        ->toBe(collect(["#{$own->id} shared-token in the subject", "#{$viaFile->id} Other"])->sort()->values()->all())
        ->and(attachmentSearchTitles($project, $user, 'shared-token', AttachmentSearchMode::Only))->toBe(["#{$viaFile->id} Other"]);
});

test('title only searches attachments only in only mode', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $issue = attachmentSearchIssue($project, 'Plain');
    attachmentSearchAttach($issue, 'titlesonly-token.txt');

    expect(attachmentSearchTitles($project, $user, 'titlesonly-token', AttachmentSearchMode::Include, titlesOnly: true))->toBe([])
        ->and(attachmentSearchTitles($project, $user, 'titlesonly-token', AttachmentSearchMode::Only, titlesOnly: true))->toHaveCount(1);
});

test('news, documents and wiki pages are found through their attachments too', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_news', 'view_documents', 'view_wiki_pages']);
    $news = News::factory()->for($project)->create(['title' => 'A news item']);
    $document = Document::factory()->for($project)->create(['title' => 'A document']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'A wiki page']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'body', 'version' => 2]);
    foreach ([$news, $document, $page] as $owner) {
        attachmentSearchAttach($owner, 'spec-diagram.txt');
    }

    $titles = attachmentSearchTitles($project, $user, 'spec-diagram', AttachmentSearchMode::Only);

    expect($titles)->toContain('A news item', 'A document', 'A wiki page')
        ->and(attachmentSearchTitles($project, $user, 'spec-diagram', AttachmentSearchMode::Exclude))->toBe([]);
});

test('attachments of records the viewer cannot see never leak', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $secret = attachmentSearchIssue($otherProject, 'Secret issue');
    attachmentSearchAttach($secret, 'leaky-token.txt');
    $noPermission = attachmentSearchMember($project, ['view_project']);
    attachmentSearchAttach(attachmentSearchIssue($project, 'Visible only with view_issues'), 'leaky-token.txt');

    $global = app(SearchService::class)->searchAcrossProjects(collect([$project]), $user, 'leaky-token', attachments: AttachmentSearchMode::Include);

    expect($global->pluck('title')->join(' '))->not->toContain('Secret issue')
        ->and($global)->toHaveCount(1)
        ->and(attachmentSearchTitles($project, $noPermission, 'leaky-token', AttachmentSearchMode::Include))->toBe([]);
});

test('a private issue attachment is hidden from a member who cannot see the issue', function () {
    $project = Project::factory()->create();
    $author = attachmentSearchMember($project, ['view_project', 'view_issues']);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => IssueVisibility::Default->value])
    );
    $issue = attachmentSearchIssue($project, 'Private thing');
    $issue->update(['is_private' => true, 'author_id' => $author->id]);
    attachmentSearchAttach($issue, 'private-token.txt');

    expect(attachmentSearchTitles($project, $viewer, 'private-token', AttachmentSearchMode::Include))->toBe([])
        ->and(attachmentSearchTitles($project, $author, 'private-token', AttachmentSearchMode::Include))->toHaveCount(1);
});

test('the search page and the API accept the attachments option', function () {
    $project = Project::factory()->create();
    $user = attachmentSearchMember($project, ['view_project', 'view_issues']);
    attachmentSearchAttach(attachmentSearchIssue($project, 'Via file'), 'ui-api-token.txt');

    $page = Livewire::actingAs($user)->test('search.index', ['project' => $project])->set('query', 'ui-api-token');
    expect($page->set('attachments', '0')->call('search')->get('results'))->toBeEmpty()
        ->and($page->set('attachments', '1')->call('search')->get('results'))->toHaveCount(1);
    $page->assertSee('添付ファイル');

    $global = Livewire::actingAs($user)->test('search.global-index')->set('query', 'ui-api-token')->set('attachments', 'only');
    expect($global->call('search')->get('results'))->toHaveCount(1);

    Passport::actingAs($user);
    expect($this->getJson('/api/v1/search?q=ui-api-token')->json('data'))->toBeEmpty()
        ->and($this->getJson('/api/v1/search?q=ui-api-token&attachments=1')->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/v1/projects/{$project->id}/search?q=ui-api-token&attachments=only")->json('data'))->toHaveCount(1);
    $this->getJson('/api/v1/search?q=x&attachments=bogus')->assertUnprocessable();
});

test('the bookmarks scope limits the global search and the API to starred projects', function () {
    $starred = Project::factory()->create();
    $other = Project::factory()->create();
    $user = attachmentSearchMember($starred, ['view_project', 'view_issues']);
    Member::factory()->for($other)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );
    attachmentSearchIssue($starred, 'bookmark-scope in starred');
    attachmentSearchIssue($other, 'bookmark-scope in other');
    $user->bookmarkedProjects()->attach($starred);

    $everything = Livewire::actingAs($user)->test('search.global-index')->set('query', 'bookmark-scope')->call('search');
    expect($everything->get('results'))->toHaveCount(2);

    $bookmarked = Livewire::actingAs($user)->test('search.global-index')
        ->set('query', 'bookmark-scope')->set('bookmarkedOnly', true)->call('search');
    expect($bookmarked->get('results')->pluck('title')->join(' '))->toContain('in starred')->not->toContain('in other');

    Passport::actingAs($user);
    $titles = collect($this->getJson('/api/v1/search?q=bookmark-scope&scope=bookmarks')->assertOk()->json('data'))->pluck('title')->join(' ');
    expect($titles)->toContain('in starred')->not->toContain('in other');

    $lonely = User::factory()->create();
    Passport::actingAs($lonely);
    expect($this->getJson('/api/v1/search?q=bookmark-scope&scope=bookmarks')->json('data'))->toBeEmpty();
});
