<?php

use App\Enums\IssueVisibility;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
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
