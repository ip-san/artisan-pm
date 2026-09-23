<?php

use App\Enums\FilterFieldType;
use App\Enums\FilterOperator;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Query\FilterSelectOptions;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\NativeColumnFilter;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Livewire\Livewire;

/**
 * A1-17 follow-up: the filter builder offers filters through Redmine's
 * "add filter" select, grouped like filters_options_for_select, and the
 * issue id filters take a comma separated list.
 */
test('the add filter select groups the issue filters like Redmine', function () {
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create();
    Project::factory()->create(['parent_id' => $parent->id]);
    $parent->refresh();

    $options = FilterSelectOptions::grouped(IssueFilterFieldRegistry::forProject($parent, $admin), ['status_id']);

    expect($options['ungrouped'])->not->toHaveKey('status_id')
        ->and($options['ungrouped'])->toHaveKeys(['tracker_id', 'assigned_to_id', 'issue_id', 'done_ratio', 'watcher_id'])
        ->and(array_keys($options['groups']))->toBe([
            __('文字列'), __('日付'), __('時間管理'), __('添付ファイル'), __('関係'), __('担当者'), __('作成者'), __('対象バージョン'), __('プロジェクト'),
        ])
        ->and(array_keys($options['groups'][__('文字列')]))->toBe(['subject', 'description', 'notes', 'any_searchable'])
        ->and(array_keys($options['groups'][__('関係')]))->toContain('parent_id', 'child_id', 'relates', 'blocks', 'copied_from')
        ->and(array_keys($options['groups'][__('担当者')]))->toBe(['member_of_group', 'assigned_to_role'])
        ->and(array_keys($options['groups'][__('作成者')]))->toBe(['author_group', 'author_role'])
        ->and(array_keys($options['groups'][__('対象バージョン')]))->toBe(['fixed_version_due_date', 'fixed_version_status'])
        ->and(array_keys($options['groups'][__('プロジェクト')]))->toBe(['project_status'])
        ->and(array_keys($options['groups'][__('時間管理')]))->toBe(['estimated_hours', 'spent_time']);
});

test('a lone date filter is not put in a group of its own', function () {
    $options = FilterSelectOptions::grouped(collect([
        new NativeColumnFilter('spent_on', 'Date', 'spent_on', FilterFieldType::Date, [FilterOperator::Equals]),
        new NativeColumnFilter('user_id', 'User', 'user_id', FilterFieldType::Select, [FilterOperator::Equals]),
    ]));

    expect($options['groups'])->toBe([])
        ->and($options['ungrouped'])->toBe(['user_id' => 'User', 'spent_on' => 'Date']);
});

test('the time entry filters on the issue and the user are grouped under them', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    $options = FilterSelectOptions::grouped(TimeEntryFilterFieldRegistry::forProject($project, $admin));

    expect(array_keys($options['groups'][__('課題')]))->toBe(['issue_tracker_id', 'issue_parent_id', 'issue_status_id', 'issue_fixed_version_id', 'issue_category_id', 'issue_subject'])
        ->and(array_keys($options['groups'][__('ユーザー')]))->toBe(['user_group', 'user_role'])
        ->and(array_keys($options['groups'][__('日付')]))->toBe(['spent_on', 'created_at'])
        ->and($options['ungrouped'])->toHaveKeys(['issue_id', 'user_id', 'author_id', 'activity_id', 'hours']);
});

test('the issue list renders the add filter select and adds the chosen filter', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($admin)
        ->test('issues.index', ['project' => $project])
        ->assertSeeHtml('id="add-filter-select"')
        ->assertSeeHtml('<optgroup label="'.__('関係').'">')
        ->call('addFilter', 'description')
        ->assertSet('activeFilterKeys', ['description'])
        ->assertSeeHtml('wire:key="filter-row-description"');
});

test('every list using the filter builder renders the add filter select', function (string $routeName, bool $needsProject) {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();

    $this->actingAs($admin)
        ->get(route($routeName, $needsProject ? ['project' => $project] : []))
        ->assertSuccessful()
        ->assertSee('id="add-filter-select"', false)
        ->assertDontSee('wire:key="add-filter-', false);
})->with([
    'issues' => ['issues.index', true],
    'global issues' => ['issues.global-index', false],
    'gantt' => ['gantt.index', true],
    'calendar' => ['calendar.index', true],
    'global calendar' => ['calendar.global-index', false],
    'time entries' => ['time-entries.index', true],
    'global time entries' => ['time-entries.global-index', false],
    'time report' => ['time-entries.report', true],
    'global time report' => ['time-entries.global-report', false],
    'projects' => ['projects.index', false],
    'users' => ['users.index', false],
]);

test('the issue id filters take a comma separated list in a text input', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $first = Issue::factory()->for($project)->create();
    Issue::factory()->for($project)->create();
    $third = Issue::factory()->for($project)->create();

    $component = Livewire::actingAs($admin)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('addFilter', 'issue_id')
        ->assertSeeHtml('placeholder="1, 2, 3" wire:model="filterValues.issue_id.0"')
        ->set('filterOperators.issue_id', '=')
        ->set('filterValues.issue_id.0', "{$first->id}, {$third->id}");

    expect($component->get('issues')->pluck('id')->sort()->values()->all())->toBe([$first->id, $third->id]);

    $component->set('filterOperators.issue_id', '>=')
        ->assertDontSeeHtml('placeholder="1, 2, 3" wire:model="filterValues.issue_id.0"')
        ->assertSeeHtml('type="number" step="0.01" wire:model="filterValues.issue_id.0"');
});

test('the parent and relation filters take a comma separated list too', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $parentA = Issue::factory()->for($project)->create();
    $parentB = Issue::factory()->for($project)->create();
    $childA = Issue::factory()->for($project)->create(['parent_id' => $parentA->id]);
    $childB = Issue::factory()->for($project)->create(['parent_id' => $parentB->id]);

    $component = Livewire::actingAs($admin)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('addFilter', 'parent_id')
        ->set('filterOperators.parent_id', '=')
        ->set('filterValues.parent_id.0', "{$parentA->id},{$parentB->id}")
        ->call('addFilter', 'relates')
        ->assertSeeHtml('placeholder="1, 2, 3" wire:model="filterValues.relates.0"');

    expect($component->get('issues')->pluck('id')->sort()->values()->all())->toBe([$childA->id, $childB->id]);
});
