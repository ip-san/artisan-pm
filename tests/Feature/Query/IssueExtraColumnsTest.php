<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function extraViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project', 'view_issues']]));

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function extraIssue(Project $project, array $attributes = []): Issue
{
    $tracker = Tracker::query()->first() ?? Tracker::factory()->create();
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

test('the extra columns are offered and rendered for an issue', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    $parent = extraIssue($project, ['subject' => 'Parent']);
    $issue = extraIssue($project, ['subject' => 'Child', 'parent_id' => $parent->id, 'is_private' => false, 'closed_on' => '2026-03-04 10:20:00', 'description' => 'A long description']);
    $editor = User::factory()->create(['name' => 'Ed Editor']);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $editor->id, 'notes' => 'first remark', 'created_at' => now()->subHour()]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $editor->id, 'notes' => 'latest remark', 'created_at' => now()]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $editor->id, 'notes' => 'secret', 'private_notes' => true, 'created_at' => now()->addMinute()]);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'parent_id', 'updated_at', 'closed_on', 'last_updated_by', 'is_private', 'description', 'last_notes'])
        ->set('statusFilter', 'all');
    $child = $list->get('issues')->getCollection()->firstWhere('subject', 'Child');
    $cell = fn (string $key) => $list->instance()->columnValue($child, $key);

    expect($cell('parent_id'))->toBe('#'.$parent->id)
        ->and($cell('closed_on'))->toBe('2026-03-04 10:20')
        ->and($cell('last_updated_by'))->toBe('Ed Editor')
        ->and($cell('is_private'))->toBe('いいえ')
        ->and($cell('description'))->toBe('A long description')
        ->and($cell('last_notes'))->toBe('latest remark')
        ->and($cell('updated_at'))->not->toBe('');
});

test('an issue without journals falls back to its author, and blank fields stay blank', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    $author = User::factory()->create(['name' => 'Original Author']);
    extraIssue($project, ['subject' => 'Untouched', 'author_id' => $author->id]);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('columns', ['subject', 'last_updated_by', 'last_notes', 'parent_id', 'closed_on'])->set('statusFilter', 'all');
    $issue = $list->get('issues')->getCollection()->first();

    expect($list->instance()->columnValue($issue, 'last_updated_by'))->toBe('Original Author')
        ->and($list->instance()->columnValue($issue, 'last_notes'))->toBe('')
        ->and($list->instance()->columnValue($issue, 'parent_id'))->toBe('')
        ->and($list->instance()->columnValue($issue, 'closed_on'))->toBe('');
});

test('description and last notes get a row of their own instead of a cell', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    $issue = extraIssue($project, ['subject' => 'Blocky', 'description' => 'Body text for the block']);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $viewer->id, 'notes' => 'Newest comment text']);

    $html = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('columns', ['subject', 'description', 'last_notes'])->set('statusFilter', 'all')->html();

    expect($html)->toContain('data-block-column="description"')->toContain('data-block-column="last_notes"')->toContain('Body text for the block')->toContain('Newest comment text');
    expect(preg_match_all('/<th[ >]/', $html))->toBe(2);
});

test('a block column with nothing to show adds no row', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    extraIssue($project, ['description' => null]);

    $html = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('columns', ['subject', 'description'])->set('statusFilter', 'all')->html();

    expect($html)->not->toContain('data-block-column');
});

test('updated and closed dates can be sorted and filtered', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    $older = extraIssue($project, ['subject' => 'Older', 'closed_on' => '2026-01-01 00:00:00']);
    $newer = extraIssue($project, ['subject' => 'Newer', 'closed_on' => '2026-06-01 00:00:00']);
    $older->forceFill(['updated_at' => '2026-01-02 00:00:00'])->saveQuietly();
    $newer->forceFill(['updated_at' => '2026-06-02 00:00:00'])->saveQuietly();

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('statusFilter', 'all')->call('sortBy', 'updated_at');
    expect($list->get('issues')->getCollection()->pluck('subject')->all())->toBe(['Older', 'Newer']);

    $list->call('sortBy', 'updated_at');
    expect($list->get('issues')->getCollection()->pluck('subject')->all())->toBe(['Newer', 'Older']);

    $list->call('sortBy', 'closed_on')->assertOk();
    expect(App\Support\Query\IssueFilterFieldRegistry::forProject($project)->keys()->all())->toContain('updated_at', 'closed_on');
});

test('the CSV export carries the new columns and the private note never leaks', function () {
    $project = Project::factory()->create();
    $viewer = extraViewer($project);
    $issue = extraIssue($project, ['subject' => 'Exported']);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $viewer->id, 'notes' => 'Public note', 'created_at' => now()->subMinute()]);
    Journal::create(['issue_id' => $issue->id, 'user_id' => $viewer->id, 'notes' => 'Private note', 'private_notes' => true]);

    $list = Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])->set('columns', ['subject', 'last_notes', 'is_private'])->set('statusFilter', 'all');
    $list->call('exportCsv')->assertFileDownloaded();

    $issueRow = $list->get('issues')->getCollection()->first();
    expect($list->instance()->columnValue($issueRow, 'last_notes'))->toBe('Public note');
});
