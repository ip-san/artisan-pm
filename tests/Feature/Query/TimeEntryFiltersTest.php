<?php

use App\Enums\FilterOperator;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Livewire\Livewire;

/**
 * A2-08: the time entry filters on the entry's issue, user, author and
 * project (Redmine's TimeEntryQuery) and the added columns.
 *
 * @param  array<int, string>  $permissions
 * @param  array<string, mixed>  $roleAttributes
 */
function timeEntryFilterMember(Project $project, array $permissions = ['view_time_entries', 'view_issues', 'save_queries'], array $roleAttributes = []): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, ...$roleAttributes]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * The entries of $project matching one filter, as the project's list sees
 * them.
 *
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function timeEntryFilter(Project $project, ?User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(TimeEntry::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('the issue attribute filters match entries by their issue', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project);
    $open = IssueStatus::factory()->create();
    $closed = IssueStatus::factory()->create();
    $bug = Tracker::factory()->create();
    $version = Version::factory()->for($project)->create();
    $category = IssueCategory::factory()->for($project)->create();
    $issueA = Issue::factory()->for($project)->create(['status_id' => $open->id, 'tracker_id' => $bug->id, 'fixed_version_id' => $version->id, 'category_id' => $category->id, 'subject' => 'Login CRASH']);
    $issueB = Issue::factory()->for($project)->create(['status_id' => $closed->id, 'subject' => 'Other']);
    $onA = TimeEntry::factory()->for($project)->create(['issue_id' => $issueA->id]);
    $onB = TimeEntry::factory()->for($project)->create(['issue_id' => $issueB->id]);
    $noIssue = TimeEntry::factory()->for($project)->create(['issue_id' => null]);

    expect(timeEntryFilter($project, $viewer, 'issue_status_id', FilterOperator::Equals, [(string) $open->id]))->toBe([$onA->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_status_id', FilterOperator::NotEquals, [(string) $open->id]))->toBe([$onB->id, $noIssue->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_tracker_id', FilterOperator::In, [(string) $bug->id]))->toBe([$onA->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_fixed_version_id', FilterOperator::Equals, [(string) $version->id]))->toBe([$onA->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_fixed_version_id', FilterOperator::NotEquals, [(string) $version->id]))->toBe([$onB->id, $noIssue->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_category_id', FilterOperator::IsNotEmpty))->toBe([$onA->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_category_id', FilterOperator::IsEmpty))->toBe([$onB->id, $noIssue->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_subject', FilterOperator::Contains, ['crash']))->toBe([$onA->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_subject', FilterOperator::NotContains, ['crash']))->toBe([$onB->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_id', FilterOperator::Equals, ["{$issueA->id}, {$issueB->id}"]))->toBe([$onA->id, $onB->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_id', FilterOperator::IsEmpty))->toBe([$noIssue->id]);
});

test('the issue and parent tree filters take the issues below', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project);
    $root = Issue::factory()->for($project)->create();
    $child = Issue::factory()->for($project)->create(['parent_id' => $root->id]);
    $grandchild = Issue::factory()->for($project)->create(['parent_id' => $child->id]);
    $onRoot = TimeEntry::factory()->for($project)->create(['issue_id' => $root->id]);
    $onChild = TimeEntry::factory()->for($project)->create(['issue_id' => $child->id]);
    $onGrandchild = TimeEntry::factory()->for($project)->create(['issue_id' => $grandchild->id]);
    $noIssue = TimeEntry::factory()->for($project)->create(['issue_id' => null]);

    expect(timeEntryFilter($project, $viewer, 'issue_id', FilterOperator::Contains, [(string) $child->id]))->toBe([$onChild->id, $onGrandchild->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_parent_id', FilterOperator::Equals, [(string) $root->id]))->toBe([$onChild->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_parent_id', FilterOperator::Contains, [(string) $root->id]))->toBe([$onChild->id, $onGrandchild->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_parent_id', FilterOperator::IsNotEmpty))->toBe([$onChild->id, $onGrandchild->id])
        ->and(timeEntryFilter($project, $viewer, 'issue_parent_id', FilterOperator::IsEmpty))->toBe([$onRoot->id, $noIssue->id]);
});

test('issue filters never match through an issue the viewer cannot see', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project, ['view_time_entries', 'view_issues'], ['issues_visibility' => 'default']);
    $status = IssueStatus::factory()->create();
    $secret = Issue::factory()->for($project)->create(['is_private' => true, 'subject' => 'Secret merger', 'status_id' => $status->id]);
    $child = Issue::factory()->for($project)->create(['parent_id' => $secret->id]);
    $onSecret = TimeEntry::factory()->for($project)->create(['issue_id' => $secret->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $child->id]);

    expect(timeEntryFilter($project, $viewer, 'issue_subject', FilterOperator::Contains, ['merger']))->toBe([])
        ->and(timeEntryFilter($project, $viewer, 'issue_status_id', FilterOperator::Equals, [(string) $status->id]))->toBe([])
        ->and(timeEntryFilter($project, $viewer, 'issue_id', FilterOperator::Contains, [(string) $secret->id]))->not->toContain($onSecret->id)
        ->and(timeEntryFilter($project, $viewer, 'issue_status_id', FilterOperator::NotEquals, [(string) $status->id]))->toContain($onSecret->id);
});

test('issue filters match nothing for a viewer without view_issues', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project, ['view_time_entries']);
    $status = IssueStatus::factory()->create();
    $issue = Issue::factory()->for($project)->create(['subject' => 'Visible subject', 'status_id' => $status->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);

    expect(timeEntryFilter($project, $viewer, 'issue_subject', FilterOperator::Contains, ['visible']))->toBe([])
        ->and(timeEntryFilter($project, $viewer, 'issue_status_id', FilterOperator::Equals, [(string) $status->id]))->toBe([])
        ->and(timeEntryFilter($project, $viewer, 'issue_parent_id', FilterOperator::IsNotEmpty))->toBe([]);
});

test('the user group and role filters match the entry user', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project);
    $developer = Role::factory()->create(['permissions' => ['log_time']]);
    $dev = User::factory()->create();
    Member::factory()->for($project)->for($dev)->create()->roles()->attach($developer);
    $outsider = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($dev);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id]);
    $byDev = TimeEntry::factory()->for($project)->create(['user_id' => $dev->id]);
    $byOutsider = TimeEntry::factory()->for($project)->create(['user_id' => $outsider->id]);

    expect(timeEntryFilter($project, $viewer, 'user_group', FilterOperator::Equals, [(string) $group->id]))->toBe([$byDev->id])
        ->and(timeEntryFilter($project, $viewer, 'user_group', FilterOperator::IsEmpty))->toBe([$byOutsider->id])
        ->and(timeEntryFilter($project, $viewer, 'user_role', FilterOperator::Equals, [(string) $developer->id]))->toBe([$byDev->id])
        ->and(timeEntryFilter($project, $viewer, 'user_role', FilterOperator::NotEquals, [(string) $developer->id]))->toBe([$byOutsider->id])
        ->and(timeEntryFilter($project, $viewer, 'user_role', FilterOperator::IsEmpty))->toBe([$byOutsider->id]);
});

test('groups the viewer cannot see are neither offered nor honoured', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project, ['view_time_entries', 'view_issues'], ['users_visibility' => 'members_of_visible_projects']);
    $memberGroup = Group::factory()->create();
    $hiddenGroup = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $memberGroup->id]);
    $hiddenUser = User::factory()->create();
    $hiddenGroup->users()->attach($hiddenUser);
    TimeEntry::factory()->for($project)->create(['user_id' => $hiddenUser->id]);

    $fields = TimeEntryFilterFieldRegistry::forProject($project, $viewer);

    expect(array_keys($fields->get('user_group')->options()))->toBe([$memberGroup->id])
        ->and(timeEntryFilter($project, $viewer, 'user_group', FilterOperator::Equals, [(string) $hiddenGroup->id]))->toBe([]);
});

test('the author filter matches who logged the entry and offers only project members', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project);
    $logger = timeEntryFilterMember($project, ['log_time', 'log_time_for_other_users']);
    $worker = timeEntryFilterMember($project, ['log_time']);
    $stranger = User::factory()->create();
    $loggedForWorker = TimeEntry::factory()->for($project)->create(['user_id' => $worker->id, 'author_id' => $logger->id]);
    TimeEntry::factory()->for($project)->create(['user_id' => $worker->id, 'author_id' => $worker->id]);

    expect(timeEntryFilter($project, $viewer, 'author_id', FilterOperator::Equals, [(string) $logger->id]))->toBe([$loggedForWorker->id])
        ->and(TimeEntryFilterFieldRegistry::forProject($project, $viewer)->get('author_id')->options())->not->toHaveKey($stranger->id)
        ->and(TimeEntryFilterFieldRegistry::forProject($project, $viewer)->get('author_id')->options())->toHaveKey($logger->id);
});

test('an own-entries viewer never sees other entries through the new filters', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project, ['view_time_entries', 'view_issues'], ['time_entries_visibility' => 'own']);
    $other = timeEntryFilterMember($project, ['log_time']);
    $issue = Issue::factory()->for($project)->create();
    $mine = TimeEntry::factory()->for($project)->create(['user_id' => $viewer->id, 'issue_id' => $issue->id]);
    TimeEntry::factory()->for($project)->create(['user_id' => $other->id, 'author_id' => $other->id, 'issue_id' => $issue->id]);

    $component = Livewire::actingAs($viewer)
        ->test('time-entries.index', ['project' => $project])
        ->call('addFilter', 'author_id')
        ->set('filterOperators.author_id', '=')
        ->set('filterValues.author_id.0', (string) $other->id);

    expect($component->get('timeEntries')->pluck('id')->all())->toBe([]);

    $component->call('removeFilter', 'author_id')
        ->call('addFilter', 'issue_id')
        ->set('filterOperators.issue_id', '=')
        ->set('filterValues.issue_id.0', (string) $issue->id);

    expect($component->get('timeEntries')->pluck('id')->all())->toBe([$mine->id]);
});

test('the cross-project list never shows entries of projects the viewer cannot see', function () {
    $visible = Project::factory()->private()->create();
    $hidden = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($visible);
    $group = Group::factory()->create();
    $worker = User::factory()->create();
    $group->users()->attach($worker);
    $role = Role::factory()->create(['permissions' => ['log_time']]);
    Member::factory()->for($hidden)->for($worker)->create()->roles()->attach($role);
    TimeEntry::factory()->for($hidden)->create(['user_id' => $worker->id, 'issue_id' => Issue::factory()->for($hidden)->create(['subject' => 'Hidden work'])->id]);
    $shown = TimeEntry::factory()->for($visible)->create();

    foreach ([
        ['project_status', '=', '1'],
        ['user_role', '=', (string) $role->id],
        ['user_group', '!', (string) $group->id],
        ['issue_subject', '!~', 'zzz'],
        ['comments', '!~', 'zzz'],
    ] as [$key, $operator, $value]) {
        $ids = Livewire::actingAs($viewer)
            ->test('time-entries.global-index')
            ->call('addFilter', $key)
            ->set("filterOperators.{$key}", $operator)
            ->set("filterValues.{$key}.0", $value)
            ->get('timeEntries')
            ->pluck('id')
            ->all();

        expect($ids)->not->toContain(TimeEntry::where('project_id', $hidden->id)->value('id'), "{$key} {$operator}");
    }

    expect(TimeEntryFilterFieldRegistry::forProjects(collect([$visible]), $viewer)->get('user_id')->options())->not->toHaveKey($worker->id)
        ->and($shown->id)->toBeInt();
});

test('an entry on an issue the viewer cannot see shows only the issue number', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project, ['view_time_entries', 'view_issues'], ['issues_visibility' => 'default']);
    $secret = Issue::factory()->for($project)->create(['is_private' => true, 'subject' => 'Secret merger']);
    TimeEntry::factory()->for($project)->create(['issue_id' => $secret->id]);

    Livewire::actingAs($viewer)
        ->test('time-entries.index', ['project' => $project])
        ->assertSee("#{$secret->id}")
        ->assertDontSee('Secret merger');

    Livewire::actingAs($viewer)
        ->test('time-entries.global-index')
        ->assertSee("#{$secret->id}")
        ->assertDontSee('Secret merger');
});

test('the project, created, week and author columns render and round-trip through a saved query', function () {
    $project = Project::factory()->private()->create(['name' => 'Columns project']);
    $viewer = timeEntryFilterMember($project);
    $author = timeEntryFilterMember($project, ['log_time'], []);
    TimeEntry::factory()->for($project)->create(['spent_on' => '2026-09-24', 'author_id' => $author->id]);

    Livewire::actingAs($viewer)
        ->test('time-entries.index', ['project' => $project])
        ->set('columns', ['project_id', 'spent_on', 'created_at', 'tweek', 'author_id', 'hours'])
        ->assertSee('Columns project')
        ->assertSee($author->fresh()->displayName())
        ->assertSeeHtml('<td wire:key="time-entry-'.TimeEntry::first()->id.'-column-tweek" class="px-4 py-2">')
        ->call('addFilter', 'issue_status_id')
        ->set('filterOperators.issue_status_id', '!')
        ->set('filterValues.issue_status_id.0', '1')
        ->set('newQueryName', 'A2-08 query')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'A2-08 query')->firstOrFail();

    expect($saved->column_names)->toBe(['project_id', 'spent_on', 'created_at', 'tweek', 'author_id', 'hours'])
        ->and(array_keys($saved->filters))->toBe(['issue_status_id']);

    Livewire::actingAs($viewer)
        ->test('time-entries.index', ['project' => $project])
        ->call('loadQuery', $saved->id)
        ->assertSet('activeFilterKeys', ['issue_status_id'])
        ->assertSet('columns', ['project_id', 'spent_on', 'created_at', 'tweek', 'author_id', 'hours']);
});

test('the week column is the ISO week of the spent date', function () {
    $project = Project::factory()->private()->create();
    $viewer = timeEntryFilterMember($project);
    $entry = TimeEntry::factory()->for($project)->create(['spent_on' => '2026-01-01']);

    $component = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project]);

    expect($component->instance()->columnValue($entry->fresh(['project', 'user', 'author', 'activity', 'issue', 'customFieldValues']), 'tweek'))->toBe('1');
});
