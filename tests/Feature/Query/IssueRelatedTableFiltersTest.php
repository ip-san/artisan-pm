<?php

use App\Enums\CustomFieldFormat;
use App\Enums\FilterOperator;
use App\Enums\ProjectStatus;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Livewire\Livewire;

/**
 * A1-17d: fixed_version_due_date, fixed_version_status, project_status,
 * spent_time and any_searchable.
 *
 * @param  array<int, string>  $permissions
 * @param  array<string, mixed>  $roleAttributes
 */
function relatedTableMember(Project $project, array $permissions = ['view_issues', 'save_queries'], array $roleAttributes = []): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, ...$roleAttributes]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function relatedTableFilter(Project $project, ?User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('the target version due date filter compares the version date', function () {
    $project = Project::factory()->create();
    $viewer = relatedTableMember($project);
    $early = Version::factory()->for($project)->create(['due_date' => '2026-10-01']);
    $late = Version::factory()->for($project)->create(['due_date' => '2026-12-01']);
    $undated = Version::factory()->for($project)->create(['due_date' => null]);
    $a = Issue::factory()->for($project)->create(['fixed_version_id' => $early->id]);
    $b = Issue::factory()->for($project)->create(['fixed_version_id' => $late->id]);
    $c = Issue::factory()->for($project)->create(['fixed_version_id' => $undated->id]);
    $d = Issue::factory()->for($project)->create(['fixed_version_id' => null]);

    expect(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::Equals, ['2026-10-01']))->toBe([$a->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::GreaterOrEqual, ['2026-11-01']))->toBe([$b->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::LessOrEqual, ['2026-11-01']))->toBe([$a->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::Between, ['2026-09-01', '2026-12-31']))->toBe([$a->id, $b->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::IsNotEmpty))->toBe([$a->id, $b->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_due_date', FilterOperator::IsEmpty))->toBe([$c->id, $d->id]);
});

test('the target version status filter matches the version status', function () {
    $project = Project::factory()->create();
    $viewer = relatedTableMember($project);
    $open = Version::factory()->for($project)->create(['status' => 'open']);
    $locked = Version::factory()->for($project)->create(['status' => 'locked']);
    $a = Issue::factory()->for($project)->create(['fixed_version_id' => $open->id]);
    $b = Issue::factory()->for($project)->create(['fixed_version_id' => $locked->id]);
    $c = Issue::factory()->for($project)->create(['fixed_version_id' => null]);

    expect(relatedTableFilter($project, $viewer, 'fixed_version_status', FilterOperator::Equals, ['locked']))->toBe([$b->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_status', FilterOperator::In, ['open', 'locked']))->toBe([$a->id, $b->id])
        ->and(relatedTableFilter($project, $viewer, 'fixed_version_status', FilterOperator::NotEquals, ['locked']))->toBe([$a->id, $c->id]);
});

test('the project status filter is offered on the cross-project list and on a project with subprojects', function () {
    $parent = Project::factory()->create();
    $child = Project::factory()->create(['parent_id' => $parent->id]);
    $viewer = relatedTableMember($parent);

    expect(IssueFilterFieldRegistry::forProject($parent->fresh(), $viewer)->has('project_status'))->toBeTrue()
        ->and(IssueFilterFieldRegistry::forProject($child->fresh(), $viewer)->has('project_status'))->toBeFalse()
        ->and(IssueFilterFieldRegistry::forProjects(collect([$child->fresh()]), $viewer)->has('project_status'))->toBeTrue();
});

test('the project status filter matches the issue project status', function () {
    $active = Project::factory()->create();
    $closed = Project::factory()->create(['status' => ProjectStatus::Closed->value]);
    $viewer = relatedTableMember($active);
    $inActive = Issue::factory()->for($active)->create();
    $inClosed = Issue::factory()->for($closed)->create();
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProjects(collect([$active, $closed]), $viewer));

    $filter = fn (FilterOperator $operator) => $engine->applyFilters(Issue::query()->whereIn('project_id', [$active->id, $closed->id]), [
        'project_status' => ['operator' => $operator->value, 'values' => ['closed']],
    ])->pluck('id')->all();

    expect($filter(FilterOperator::Equals))->toBe([$inClosed->id])
        ->and($filter(FilterOperator::NotEquals))->toBe([$inActive->id]);
});

test('the spent time filter is only offered to a viewer who may see time entries', function () {
    $project = Project::factory()->create();

    expect(IssueFilterFieldRegistry::forProject($project, relatedTableMember($project))->has('spent_time'))->toBeFalse()
        ->and(IssueFilterFieldRegistry::forProject($project, relatedTableMember($project, ['view_issues', 'view_time_entries']))->has('spent_time'))->toBeTrue();
});

test('the spent time filter compares the hours logged on the issue', function () {
    $project = Project::factory()->create();
    $viewer = relatedTableMember($project, ['view_issues', 'view_time_entries']);
    $little = Issue::factory()->for($project)->create();
    $lots = Issue::factory()->for($project)->create();
    $none = Issue::factory()->for($project)->create();
    TimeEntry::factory()->for($project)->create(['issue_id' => $little->id, 'hours' => 1.5]);
    TimeEntry::factory()->for($project)->count(2)->create(['issue_id' => $lots->id, 'hours' => 4]);

    expect(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::Equals, ['1.5']))->toBe([$little->id])
        ->and(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::GreaterOrEqual, ['8']))->toBe([$lots->id])
        ->and(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::LessOrEqual, ['2']))->toBe([$little->id, $none->id])
        ->and(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::Between, ['1', '8']))->toBe([$little->id, $lots->id])
        ->and(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::IsNotEmpty))->toBe([$little->id, $lots->id])
        ->and(relatedTableFilter($project, $viewer, 'spent_time', FilterOperator::IsEmpty))->toBe([$none->id]);
});

test('the searchable text filter finds issues by subject, description and searchable custom fields', function () {
    $project = Project::factory()->create();
    $viewer = relatedTableMember($project);
    $bySubject = Issue::factory()->for($project)->create(['subject' => 'printer jam on floor two', 'description' => 'x']);
    $byDescription = Issue::factory()->for($project)->create(['subject' => 'Other', 'description' => 'The printer is on floor three']);
    $byField = Issue::factory()->for($project)->create(['subject' => 'Third', 'description' => 'y']);
    $unrelated = Issue::factory()->for($project)->create(['subject' => 'Nothing', 'description' => 'z']);
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::String->value, 'searchable' => true]);
    CustomFieldValue::create(['custom_field_id' => $field->id, 'customized_type' => 'issue', 'customized_id' => $byField->id, 'value_string' => 'printer floor']);

    expect(relatedTableFilter($project, $viewer, 'any_searchable', FilterOperator::Contains, ['printer floor']))->toBe([$bySubject->id, $byDescription->id, $byField->id])
        ->and(relatedTableFilter($project, $viewer, 'any_searchable', FilterOperator::Contains, ['printer two']))->toBe([$bySubject->id])
        ->and(relatedTableFilter($project, $viewer, 'any_searchable', FilterOperator::NotContains, ['two three']))->toBe([$byField->id, $unrelated->id])
        ->and(relatedTableFilter($project, $viewer, 'any_searchable', FilterOperator::Contains, ['nomatch']))->toBe([]);
});

test('the new filters survive a saved query round trip', function () {
    $project = Project::factory()->create();
    $user = relatedTableMember($project, ['view_issues', 'save_queries', 'view_time_entries']);
    $version = Version::factory()->for($project)->create(['status' => 'locked']);
    $match = Issue::factory()->for($project)->create(['subject' => 'needle here', 'fixed_version_id' => $version->id]);
    Issue::factory()->for($project)->create(['subject' => 'needle there']);
    TimeEntry::factory()->for($project)->create(['issue_id' => $match->id, 'hours' => 2]);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('addFilter', 'any_searchable')
        ->set('filterOperators.any_searchable', '~')
        ->set('filterValues.any_searchable.0', 'needle')
        ->call('addFilter', 'fixed_version_status')
        ->set('filterOperators.fixed_version_status', '=')
        ->set('filterValues.fixed_version_status.0', 'locked')
        ->call('addFilter', 'spent_time')
        ->set('filterOperators.spent_time', '>=')
        ->set('filterValues.spent_time.0', '1')
        ->set('newQueryName', 'Related tables')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Related tables')->firstOrFail();

    expect(array_keys($saved->filters))->toBe(['any_searchable', 'fixed_version_status', 'spent_time']);

    $issues = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('loadQuery', $saved->id)
        ->get('issues');

    expect($issues->pluck('id')->all())->toBe([$match->id]);
});

test('matching issues from another project or invisible issues never reach the list', function () {
    $project = Project::factory()->create();
    $user = relatedTableMember($project, ['view_issues', 'view_time_entries'], ['issues_visibility' => 'default']);
    $version = Version::factory()->for($project)->create(['status' => 'locked', 'due_date' => '2026-10-01', 'sharing' => 'system']);
    $elsewhereProject = Project::factory()->create();

    $visible = Issue::factory()->for($project)->create(['subject' => 'needle', 'fixed_version_id' => $version->id]);
    $invisible = Issue::factory()->for($project)->create(['subject' => 'needle', 'fixed_version_id' => $version->id, 'is_private' => true]);
    $elsewhere = Issue::factory()->for($elsewhereProject)->create(['subject' => 'needle', 'fixed_version_id' => $version->id]);

    foreach ([$visible, $invisible, $elsewhere] as $issue) {
        TimeEntry::factory()->create(['project_id' => $issue->project_id, 'issue_id' => $issue->id, 'hours' => 3]);
    }

    foreach ([['any_searchable', '~', 'needle'], ['fixed_version_status', '=', 'locked'], ['fixed_version_due_date', '=', '2026-10-01'], ['spent_time', '=', '3']] as [$key, $operator, $value]) {
        $issues = Livewire::actingAs($user)
            ->test('issues.index', ['project' => $project])
            ->set('statusFilter', 'all')
            ->call('addFilter', $key)
            ->set("filterOperators.{$key}", $operator)
            ->set("filterValues.{$key}.0", $value)
            ->get('issues');

        expect($issues->pluck('id')->all())->toBe([$visible->id]);
    }
});
