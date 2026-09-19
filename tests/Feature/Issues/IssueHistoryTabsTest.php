<?php

use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Preferences\UserPreferences;
use Livewire\Livewire;

/**
 * @param  array<int, string>  $permissions
 */
function historyViewer(Project $project, array $permissions = ['view_project', 'view_issues']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

/**
 * @return array{0: Project, 1: Issue}
 */
function historyIssue(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    return [$project, $issue];
}

function historyJournals(Issue $issue, User $author): void
{
    Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'a plain comment', 'created_at' => now()->subHours(3)]);
    $changed = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => null, 'created_at' => now()->subHours(2)]);
    JournalDetail::create(['journal_id' => $changed->id, 'property' => 'attr', 'prop_key' => 'done_ratio', 'old_value' => '0', 'new_value' => '40']);
    $both = Journal::create(['issue_id' => $issue->id, 'user_id' => $author->id, 'notes' => 'comment with a change', 'created_at' => now()->subHour()]);
    JournalDetail::create(['journal_id' => $both->id, 'property' => 'attr', 'prop_key' => 'done_ratio', 'old_value' => '40', 'new_value' => '80']);
}

test('the history opens on everything by default', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);
    historyJournals($issue, $viewer);

    $page = Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue]);

    expect($page->get('activeHistoryTab'))->toBe('history')
        ->and($page->get('historyTabs'))->toBe(['history' => 'すべて', 'notes' => 'コメント', 'properties' => 'プロパティ変更']);
    $page->assertSee('a plain comment')->assertSee('comment with a change')->assertSee('進捗率');
});

test('the comments tab keeps only journals that have notes', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);
    historyJournals($issue, $viewer);

    $page = Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('setHistoryTab', 'notes');

    expect($page->get('activeHistoryTab'))->toBe('notes');
    $page->assertSee('a plain comment')->assertSee('comment with a change');
    expect(substr_count($page->html(), 'wire:key="journal-'))->toBe(2);
});

test('the property changes tab keeps only journals that have details', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);
    historyJournals($issue, $viewer);

    $page = Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('setHistoryTab', 'properties');

    $page->assertDontSee('a plain comment')->assertSee('comment with a change');
    expect(substr_count($page->html(), 'wire:key="journal-'))->toBe(2);
});

test('an unknown tab falls back to the default', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);

    $page = Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->call('setHistoryTab', 'nonsense');

    expect($page->get('activeHistoryTab'))->toBe('history');
});

test('the user\'s history_default_tab decides the opening tab', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);
    UserPreferences::save($viewer, ['history_default_tab' => 'notes']);

    expect(Livewire::actingAs($viewer->fresh())->test('issues.show', ['project' => $project, 'issue' => $issue])->get('activeHistoryTab'))->toBe('notes');
});

test('the tab can be chosen through the URL', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project);

    $page = Livewire::withQueryParams(['tab' => 'properties'])->actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue]);

    expect($page->get('activeHistoryTab'))->toBe('properties');
});

test('related revisions get a tab only for someone who may see changesets', function () {
    [$project, $issue] = historyIssue();
    $repository = Repository::factory()->for($project)->create();
    $changeset = Changeset::factory()->for($repository)->create(['comments' => 'Fix the thing (refs #'.$issue->id.')', 'revision' => 'abcdef1234567890']);
    $issue->changesets()->attach($changeset);
    $blind = historyViewer($project);
    $sighted = historyViewer($project, ['view_project', 'view_issues', 'view_changesets']);

    expect(Livewire::actingAs($blind)->test('issues.show', ['project' => $project, 'issue' => $issue->fresh()])->get('historyTabs'))->not->toHaveKey('changesets');

    $page = Livewire::actingAs($sighted)->test('issues.show', ['project' => $project, 'issue' => $issue->fresh()]);
    expect($page->get('historyTabs'))->toHaveKey('changesets');

    $page->call('setHistoryTab', 'changesets')->assertSee('abcdef12')->assertSee('Fix the thing');
});

test('a viewer with no changesets never sees the changesets tab', function () {
    [$project, $issue] = historyIssue();
    $viewer = historyViewer($project, ['view_project', 'view_issues', 'view_changesets']);

    expect(Livewire::actingAs($viewer)->test('issues.show', ['project' => $project, 'issue' => $issue])->get('historyTabs'))->not->toHaveKey('changesets');
});

test('the profile stores the default tab and rejects unknown ones', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('profile.index')->set('history_default_tab', 'properties')->call('savePreferences')->assertHasNoErrors();
    expect($user->fresh()->preference('history_default_tab'))->toBe('properties');

    Livewire::actingAs($user)->test('profile.index')->set('history_default_tab', 'changesets')->call('savePreferences')->assertHasErrors(['history_default_tab']);
});
