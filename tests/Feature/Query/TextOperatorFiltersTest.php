<?php

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\SearchService;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TextMatch;

/**
 * A1-36: the text operators `*~`/`^`/`$`, Redmine's word splitting,
 * case-insensitive matching and the filters of core fields every tracker
 * disables.
 *
 * @param  array<int, string>  $permissions
 */
function textOperatorMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @return array<int, int>
 */
function textOperatorFilter(Project $project, ?User $viewer, string $key, string $operator, string $value): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator, 'values' => [$value]],
    ])->orderBy('id')->pluck('id')->all();
}

test('the tokenizer splits words and keeps quoted phrases like Redmine', function () {
    expect(TextMatch::tokens('hello "bye bye"'))->toBe(['hello', 'bye bye'])
        ->and(TextMatch::tokens('a bb 課 c'))->toBe(['bb', '課'])
        ->and(TextMatch::tokens('one two one three four five six'))->toBe(['one', 'two', 'three', 'four', 'five'])
        ->and(TextMatch::tokens('x'))->toBe([]);
});

test('contains needs every word in any order, ignoring case', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $both = Issue::factory()->for($project)->create(['subject' => 'Login PAGE crashes']);
    $one = Issue::factory()->for($project)->create(['subject' => 'Login is slow']);
    Issue::factory()->for($project)->create(['subject' => 'Unrelated']);

    expect(textOperatorFilter($project, $viewer, 'subject', '~', 'page login'))->toBe([$both->id])
        ->and(textOperatorFilter($project, $viewer, 'subject', '~', 'LOGIN'))->toBe([$both->id, $one->id])
        ->and(textOperatorFilter($project, $viewer, 'subject', '~', '"page crashes"'))->toBe([$both->id]);
});

test('contains any needs one word and does not contain excludes every word', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $login = Issue::factory()->for($project)->create(['subject' => 'Login fails']);
    $export = Issue::factory()->for($project)->create(['subject' => 'CSV export']);
    $other = Issue::factory()->for($project)->create(['subject' => 'Unrelated']);

    expect(textOperatorFilter($project, $viewer, 'subject', '*~', 'login export'))->toBe([$login->id, $export->id])
        ->and(textOperatorFilter($project, $viewer, 'subject', '!~', 'login export'))->toBe([$other->id]);
});

test('starts with and ends with match at the edges of the text', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $a = Issue::factory()->for($project)->create(['subject' => 'Crash on save']);
    $b = Issue::factory()->for($project)->create(['subject' => 'Save causes crash']);

    expect(textOperatorFilter($project, $viewer, 'subject', '^', 'crash'))->toBe([$a->id])
        ->and(textOperatorFilter($project, $viewer, 'subject', '$', 'CRASH'))->toBe([$b->id])
        ->and(textOperatorFilter($project, $viewer, 'subject', '^', '100%'))->toBe([]);
});

test('the new operators on notes still skip private notes the viewer may not read', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $privileged = textOperatorMember($project, ['view_issues', 'view_private_notes']);
    $issue = Issue::factory()->for($project)->create();
    Journal::create(['issue_id' => $issue->id, 'user_id' => User::factory()->create()->id, 'notes' => 'Secret workaround', 'private_notes' => true]);

    expect(textOperatorFilter($project, $viewer, 'notes', '*~', 'secret other'))->toBe([])
        ->and(textOperatorFilter($project, $viewer, 'notes', '^', 'secret'))->toBe([])
        ->and(textOperatorFilter($project, $privileged, 'notes', '*~', 'secret other'))->toBe([$issue->id])
        ->and(textOperatorFilter($project, $privileged, 'notes', '$', 'WORKAROUND'))->toBe([$issue->id]);
});

test('the any searchable filter supports contains any and stays inside visible projects', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $login = Issue::factory()->for($project)->create(['subject' => 'Login fails']);
    $export = Issue::factory()->for($project)->create(['subject' => 'Csv Export']);
    $hidden = Project::factory()->create();
    Issue::factory()->for($hidden)->create(['subject' => 'login export']);

    expect(textOperatorFilter($project, $viewer, 'any_searchable', '*~', 'LOGIN export'))->toBe([$login->id, $export->id])
        ->and(textOperatorFilter($project, $viewer, 'any_searchable', '~', 'LOGIN export'))->toBe([]);
});

test('A15-04: the any searchable filter also matches journal notes, respecting view_private_notes', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project);
    $privileged = textOperatorMember($project, ['view_issues', 'view_private_notes']);
    $publicNoteIssue = Issue::factory()->for($project)->create(['subject' => 'Unrelated subject']);
    Journal::create(['issue_id' => $publicNoteIssue->id, 'user_id' => User::factory()->create()->id, 'notes' => 'Workaround documented here', 'private_notes' => false]);
    $privateNoteIssue = Issue::factory()->for($project)->create(['subject' => 'Also unrelated']);
    Journal::create(['issue_id' => $privateNoteIssue->id, 'user_id' => User::factory()->create()->id, 'notes' => 'Secret workaround details', 'private_notes' => true]);

    expect(textOperatorFilter($project, $viewer, 'any_searchable', '*~', 'workaround'))->toBe([$publicNoteIssue->id])
        ->and(textOperatorFilter($project, $privileged, 'any_searchable', '*~', 'workaround'))->toBe([$publicNoteIssue->id, $privateNoteIssue->id]);
});

test('the search ignores case', function () {
    $project = Project::factory()->create();
    $viewer = textOperatorMember($project, ['view_project', 'search_project', 'view_issues']);
    $issue = Issue::factory()->for($project)->create(['subject' => 'Fix LOGIN Bug']);

    $results = app(SearchService::class)->search($project, $viewer, 'login bug');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toContain((string) $issue->id);
});

test('filters of core fields that every tracker disables are not offered', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'due_date', 'description', 'parent_id']]);
    $task = Tracker::factory()->create(['disabled_core_fields' => ['category_id', 'description', 'parent_id']]);
    $project->trackers()->sync([$bug->id, $task->id]);
    $viewer = textOperatorMember($project);

    $keys = IssueFilterFieldRegistry::forProject($project->fresh(), $viewer)->keys();

    expect($keys)->not->toContain('category_id')
        ->and($keys)->not->toContain('description')
        ->and($keys)->toContain('due_date')
        ->and($keys)->toContain('parent_id')
        ->and($keys)->toContain('subject');

    $global = IssueFilterFieldRegistry::forProjects(collect([$project->fresh()]), $viewer)->keys();

    expect($global)->not->toContain('category_id')
        ->and($global)->toContain('due_date');
});

test('a stored filter on a field every tracker disables is ignored', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create(['disabled_core_fields' => ['description']]);
    $project->trackers()->sync([$tracker->id]);
    $viewer = textOperatorMember($project);
    $a = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'description' => 'alpha']);
    $b = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'description' => 'beta']);

    expect(textOperatorFilter($project->fresh(), $viewer, 'description', '~', 'alpha'))->toBe([$a->id, $b->id]);
});
