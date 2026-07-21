<?php

use App\Models\Board;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
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
