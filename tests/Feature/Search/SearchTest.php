<?php

use App\Models\Board;
use App\Models\Changeset;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WikiPage;
use Livewire\Livewire;

function searchMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('an empty query returns no results', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);

    $component = Livewire::actingAs($user)->test('search.index', ['project' => $project]);

    expect($component->get('results'))->toBeEmpty();
});

test('a matching issue subject and description are found', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'Fix login bug',
        'description' => 'Users cannot authenticate with special characters',
    ]);
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'Unrelated issue',
    ]);

    $component = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'login')
        ->call('search');

    $results = $component->get('results');

    expect($results)->toHaveCount(1)
        ->and($results->first()->type)->toBe('issue')
        ->and($results->first()->title)->toContain((string) $issue->id);
});

test('a member without view_issues does not see issue results', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project']);
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'Findable subject',
    ]);

    $component = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'Findable')
        ->call('search');

    expect($component->get('results'))->toBeEmpty();
});

test('a wiki page is found by title or by its current version text', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_wiki_pages']);
    $page = WikiPage::factory()->for($project)->create(['title' => 'Deployment Guide']);
    $page->versions()->create(['author_id' => $user->id, 'text' => 'How to deploy with zero downtime', 'version' => 2]);

    $byTitle = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'Deployment')
        ->call('search')
        ->get('results');

    $byContent = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'zero downtime')
        ->call('search')
        ->get('results');

    expect($byTitle->pluck('type'))->toContain('wiki-page')
        ->and($byContent->pluck('type'))->toContain('wiki-page');
});

test('news, documents, and forum messages are all searchable', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_news', 'view_documents', 'view_messages']);

    News::factory()->for($project)->create(['title' => 'Release notes', 'description' => 'unique-news-token']);
    Document::factory()->for($project)->create(['title' => 'Spec sheet', 'description' => 'unique-document-token']);
    $board = Board::factory()->for($project)->create();
    Message::factory()->for($board)->create(['subject' => 'Forum topic', 'content' => 'unique-message-token']);

    $newsResults = Livewire::actingAs($user)->test('search.index', ['project' => $project])->set('query', 'unique-news-token')->call('search')->get('results');
    $documentResults = Livewire::actingAs($user)->test('search.index', ['project' => $project])->set('query', 'unique-document-token')->call('search')->get('results');
    $messageResults = Livewire::actingAs($user)->test('search.index', ['project' => $project])->set('query', 'unique-message-token')->call('search')->get('results');

    expect($newsResults->pluck('type'))->toContain('news')
        ->and($documentResults->pluck('type'))->toContain('document')
        ->and($messageResults->pluck('type'))->toContain('message');
});

test('a reply message result links to its parent topic', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_messages']);
    $board = Board::factory()->for($project)->create();
    $topic = Message::factory()->for($board)->create(['subject' => 'Original topic']);
    Message::factory()->for($board)->create(['parent_id' => $topic->id, 'content' => 'unique-reply-token']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'unique-reply-token')
        ->call('search')
        ->get('results');

    expect($results->first()->url)->toContain((string) $topic->id);
});

test('a changeset is found by its commit message and links to the revision', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_changesets']);
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create(['comments' => 'Fix unique-commit-token in the parser']);
    Changeset::factory()->for($repository)->create(['comments' => 'Unrelated commit']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'unique-commit-token')
        ->call('search')
        ->get('results');

    expect($results)->toHaveCount(1)
        ->and($results->first()->type)->toBe('changeset')
        ->and($results->first()->url)->toContain((string) $changeset->id);
});

test('a member without view_changesets does not see changeset results', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project']);
    $repository = Repository::factory()->for($project)->create();
    Changeset::factory()->for($repository)->create(['comments' => 'Findable commit message']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'Findable')
        ->call('search')
        ->get('results');

    expect($results->pluck('type'))->not->toContain('changeset');
});

test('the current project itself is found by name or description', function () {
    $project = Project::factory()->create(['name' => 'Unique Project Name', 'description' => 'unique-project-description-token']);
    $user = searchMember($project, ['view_project']);

    $byName = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'Unique Project Name')
        ->call('search')
        ->get('results');

    $byDescription = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'unique-project-description-token')
        ->call('search')
        ->get('results');

    expect($byName->pluck('type'))->toContain('project')
        ->and($byDescription->pluck('type'))->toContain('project');
});

test('an issue is found by a searchable custom field value', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['searchable' => true]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'Unrelated subject',
    ]);
    $issue->setCustomFieldValues([$field->id => 'unique-custom-field-token']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'unique-custom-field-token')
        ->call('search')
        ->get('results');

    expect($results->pluck('type'))->toContain('issue')
        ->and($results->first()->title)->toContain((string) $issue->id);
});

test('a custom field value is not searchable unless the field is marked searchable', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['searchable' => false]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'Unrelated subject',
    ]);
    $issue->setCustomFieldValues([$field->id => 'non-searchable-token']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'non-searchable-token')
        ->call('search')
        ->get('results');

    expect($results)->toBeEmpty();
});

test('search results are scoped to the current project only', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    Member::factory()->for($otherProject)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );

    Issue::factory()->for($otherProject)->create([
        'tracker_id' => Tracker::factory(),
        'status_id' => IssueStatus::factory(),
        'priority_id' => Enumeration::factory(),
        'author_id' => User::factory(),
        'subject' => 'cross-project-unique-token',
    ]);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'cross-project-unique-token')
        ->call('search')
        ->get('results');

    expect($results)->toBeEmpty();
});

test('all-words matching requires every word; any-word matching requires just one', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $both = Issue::factory()->for($project)->create(['subject' => 'alpha beta report']);
    $oneOnly = Issue::factory()->for($project)->create(['subject' => 'alpha only here']);

    $allWords = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'alpha beta')
        ->get('results');

    expect($allWords->pluck('title')->join(' '))->toContain("#{$both->id}")
        ->not->toContain("#{$oneOnly->id}");

    $anyWord = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'alpha beta')
        ->set('allWords', false)
        ->get('results');

    expect($anyWord->pluck('title')->join(' '))->toContain("#{$both->id}")
        ->toContain("#{$oneOnly->id}");
});

test('titles-only mode ignores body matches', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $titleMatch = Issue::factory()->for($project)->create(['subject' => 'quasar in title']);
    $bodyMatch = Issue::factory()->for($project)->create(['subject' => 'unrelated', 'description' => 'quasar only in the body']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'quasar')
        ->set('titlesOnly', true)
        ->get('results');

    $titles = $results->pluck('title')->join(' ');

    expect($titles)->toContain("#{$titleMatch->id}")
        ->not->toContain("#{$bodyMatch->id}");
});

test('open-issues-only mode excludes closed issues but leaves other types alone', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues', 'view_news']);
    $openStatus = IssueStatus::factory()->create(['is_closed' => false]);
    $closedStatus = IssueStatus::factory()->create(['is_closed' => true]);
    $open = Issue::factory()->for($project)->create(['subject' => 'nebula open', 'status_id' => $openStatus->id]);
    $closed = Issue::factory()->for($project)->create(['subject' => 'nebula closed', 'status_id' => $closedStatus->id]);
    News::factory()->for($project)->create(['title' => 'nebula news']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', 'nebula')
        ->set('openIssuesOnly', true)
        ->get('results');

    $titles = $results->pluck('title')->join(' ');

    expect($titles)->toContain("#{$open->id}")
        ->not->toContain("#{$closed->id}")
        ->and($results->pluck('type'))->toContain('news');
});

test('a #123 query jumps straight to that issue when it is visible', function () {
    $project = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create();

    Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', "#{$issue->id}")
        ->call('search')
        ->assertRedirect(route('issues.show', [$project, $issue]));

    // A bare numeric query jumps too.
    Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', (string) $issue->id)
        ->call('search')
        ->assertRedirect(route('issues.show', [$project, $issue]));
});

test('a #123 query for a nonexistent or foreign issue falls through to a normal search', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = searchMember($project, ['view_project', 'view_issues']);
    $foreignIssue = Issue::factory()->for($otherProject)->create();

    Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', '#999999')
        ->call('search')
        ->assertNoRedirect();

    Livewire::actingAs($user)
        ->test('search.index', ['project' => $project])
        ->set('query', "#{$foreignIssue->id}")
        ->call('search')
        ->assertNoRedirect();
});

test('subprojects are excluded from the search by default', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $user = searchMember($parent, ['view_project', 'view_issues']);
    Member::factory()->for($child)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );

    Issue::factory()->for($child)->create(['subject' => 'subproject-only-token']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $parent])
        ->set('query', 'subproject-only-token')
        ->call('search')
        ->get('results');

    expect($results)->toBeEmpty();
});

test('the include-subprojects toggle expands the search into visible descendants', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $hiddenChild = Project::factory()->create(['parent_id' => $parent->id, 'is_public' => false]);
    $user = searchMember($parent, ['view_project', 'view_issues']);
    Member::factory()->for($child)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues']])
    );

    Issue::factory()->for($child)->create(['subject' => 'visible-subproject-token']);
    Issue::factory()->for($hiddenChild)->create(['subject' => 'hidden-subproject-token']);

    $results = Livewire::actingAs($user)
        ->test('search.index', ['project' => $parent])
        ->set('query', 'subproject-token')
        ->set('includeSubprojects', true)
        ->call('search')
        ->get('results');

    expect($results->pluck('title')->join(' '))->toContain('visible-subproject-token')
        ->and($results->pluck('title')->join(' '))->not->toContain('hidden-subproject-token');
});
