<?php

use App\Enums\QueryType;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function changesMember(Project $project, array $permissions = ['view_project', 'view_issues'], string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility])
    );

    return $user;
}

function changesIssue(Project $project, string $subject = 'Changed issue', array $attributes = []): Issue
{
    $tracker = Tracker::query()->firstOrCreate(['name' => 'Bug']);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
        ...$attributes,
    ]);
}

test('the project feed lists journals newest first with their notes and changes', function () {
    $project = Project::factory()->create(['name' => 'Alpha']);
    $reader = changesMember($project);
    $issue = changesIssue($project);
    $older = Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => 'Older note', 'created_at' => now()->subHours(3)]);
    $newer = Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => 'Newer <b>note</b>', 'created_at' => now()->subHour()]);
    JournalDetail::create(['journal_id' => $newer->id, 'property' => 'attr', 'prop_key' => 'done_ratio', 'old_value' => '0', 'new_value' => '50']);

    $response = $this->actingAs($reader)->get(route('issues.changes-atom', $project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/atom+xml');
    $body = $response->getContent();
    expect(strpos($body, 'Newer'))->toBeLessThan(strpos($body, 'Older note'))
        ->and($body)->toContain('Alpha - Bug #'.$issue->id.': Changed issue')
        ->and($body)->toContain('進捗率: 0 → 50')
        ->and($body)->toContain('&amp;lt;b&amp;gt;note')
        ->and($body)->not->toContain('<b>note</b>');
});

test('the global feed spans the projects the reader may see and nothing else', function () {
    $mine = Project::factory()->create();
    $others = Project::factory()->create(['is_public' => false]);
    $reader = changesMember($mine);
    $seen = changesIssue($mine, 'Seen through the feed');
    $unseen = changesIssue($others, 'Not for this reader');
    Journal::create(['issue_id' => $seen->id, 'user_id' => $reader->id, 'notes' => 'visible']);
    Journal::create(['issue_id' => $unseen->id, 'user_id' => $reader->id, 'notes' => 'hidden']);

    $body = $this->actingAs($reader)->get(route('issues.global-changes-atom'))->assertOk()->getContent();

    expect($body)->toContain('Seen through the feed')->not->toContain('Not for this reader');
});

test('private issues and private notes stay hidden from those who may not see them', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project, ['view_project', 'view_issues'], 'default');
    $privateOwner = changesMember($project);
    $public = changesIssue($project, 'Public issue');
    $private = changesIssue($project, 'Private issue', ['is_private' => true, 'author_id' => $privateOwner->id]);
    Journal::create(['issue_id' => $public->id, 'user_id' => $privateOwner->id, 'notes' => 'ordinary note']);
    Journal::create(['issue_id' => $public->id, 'user_id' => $privateOwner->id, 'notes' => 'secret note', 'private_notes' => true]);
    Journal::create(['issue_id' => $private->id, 'user_id' => $privateOwner->id, 'notes' => 'inside private issue']);

    $body = $this->actingAs($reader)->get(route('issues.changes-atom', $project))->getContent();

    expect($body)->toContain('ordinary note')->not->toContain('secret note')->not->toContain('inside private issue');

    $owner = $this->actingAs($privateOwner)->get(route('issues.changes-atom', $project))->getContent();
    expect($owner)->toContain('secret note')->toContain('inside private issue');
});

test('the feed is capped at 25 entries', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project);
    $issue = changesIssue($project);

    foreach (range(1, 30) as $i) {
        Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => "note {$i}", 'created_at' => now()->subMinutes(60 - $i)]);
    }

    $body = $this->actingAs($reader)->get(route('issues.changes-atom', $project))->getContent();

    expect(substr_count($body, '<entry>'))->toBe(25)->and($body)->toContain('note 30')->not->toContain('note 5<');
});

test('a reader without view_issues gets nothing', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $noAccess = changesMember($project, ['view_project']);

    $this->actingAs($noAccess)->get(route('issues.changes-atom', $project))->assertForbidden();
});

test('the atom key lets a feed reader in and no key means the login page', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $reader = changesMember($project);
    Journal::create(['issue_id' => changesIssue($project)->id, 'user_id' => $reader->id, 'notes' => 'keyed']);

    $this->get(route('issues.changes-atom', $project))->assertRedirect(route('login'));
    $this->get(route('issues.changes-atom', [$project, 'key' => $reader->atomKey()]))->assertOk()->assertSee('keyed', false);
});

test('the issue list links to the change feed with the reader\'s key', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project);

    $html = Livewire::actingAs($reader)->test('issues.index', ['project' => $project])->html();

    expect($html)->toContain('issues/changes.atom?key='.$reader->fresh()->atom_key);
});

test('the changes feed honours the list filters and status choice, and covers open issues by default', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project);
    $alpha = changesIssue($project, 'Alpha issue');
    $beta = changesIssue($project, 'Beta issue');
    $closed = changesIssue($project, 'Closed issue', ['status_id' => IssueStatus::factory()->create(['is_closed' => true])->id]);
    foreach ([$alpha, $beta, $closed] as $issue) {
        Journal::create(['issue_id' => $issue->id, 'user_id' => $reader->id, 'notes' => "Note on {$issue->subject}"]);
    }

    $this->actingAs($reader)->get(route('issues.changes-atom', $project))
        ->assertOk()->assertSee('Note on Alpha issue')->assertSee('Note on Beta issue')->assertDontSee('Note on Closed issue');

    $this->actingAs($reader)->get(route('issues.changes-atom', [$project, 'statusFilter' => 'all']))
        ->assertSee('Note on Closed issue');

    $this->actingAs($reader)->get(route('issues.changes-atom', [$project, 'statusFilter' => 'all', 'activeFilterKeys' => ['subject'], 'filterOperators' => ['subject' => '~'], 'filterValues' => ['subject' => ['Alpha']]]))
        ->assertSee('Note on Alpha issue')->assertDontSee('Note on Beta issue')->assertDontSee('Note on Closed issue');

    Livewire::actingAs($reader)->test('issues.index', ['project' => $project])
        ->set('activeFilterKeys', ['subject'])->set('filterOperators', ['subject' => '~'])->set('filterValues', ['subject' => ['Alpha']])
        ->assertSeeHtml('filterValues%5Bsubject%5D%5B0%5D=Alpha');
});

test('the changes feed applies a saved query the reader may see and refuses one they may not', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project);
    $owner = changesMember($project);
    $alpha = changesIssue($project, 'Alpha issue');
    $beta = changesIssue($project, 'Beta issue');
    Journal::create(['issue_id' => $alpha->id, 'user_id' => $reader->id, 'notes' => 'Alpha note']);
    Journal::create(['issue_id' => $beta->id, 'user_id' => $reader->id, 'notes' => 'Beta note']);
    $make = fn (string $visibility) => Query::create([
        'name' => "Query {$visibility}", 'type' => QueryType::Issue->value, 'user_id' => $owner->id, 'project_id' => $project->id,
        'visibility' => $visibility, 'filters' => ['subject' => ['operator' => '~', 'values' => ['Beta']]], 'column_names' => ['subject'], 'sort_criteria' => [],
    ]);
    $public = $make('public');
    $private = $make('private');

    $this->actingAs($reader)->get(route('issues.changes-atom', [$project, 'query_id' => $public->id]))
        ->assertOk()->assertSee('Beta note')->assertDontSee('Alpha note');
    $this->actingAs($reader)->get(route('issues.changes-atom', [$project, 'query_id' => $private->id]))->assertForbidden();
    $this->actingAs($reader)->get(route('issues.changes-atom', [$project, 'query_id' => 999999]))->assertNotFound();
});

test('filters on the changes feed cannot reveal journals of issues the reader may not see', function () {
    $project = Project::factory()->create();
    $reader = changesMember($project, visibility: 'own');
    $mine = changesIssue($project, 'Mine issue', ['author_id' => $reader->id]);
    $theirs = changesIssue($project, 'Their issue');
    Journal::create(['issue_id' => $mine->id, 'user_id' => $reader->id, 'notes' => 'Mine note']);
    Journal::create(['issue_id' => $theirs->id, 'user_id' => $reader->id, 'notes' => 'Their note']);

    $this->actingAs($reader)->get(route('issues.global-changes-atom', ['statusFilter' => 'all', 'activeFilterKeys' => ['subject'], 'filterOperators' => ['subject' => '~'], 'filterValues' => ['subject' => ['issue']]]))
        ->assertOk()->assertSee('Mine note')->assertDontSee('Their note');
});
