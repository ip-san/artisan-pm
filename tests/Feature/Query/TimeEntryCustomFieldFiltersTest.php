<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\QueryFilterEngine;
use App\Support\Query\TimeEntryFilterFieldRegistry;
use Livewire\Livewire;

/**
 * A2-08b: custom field filters (cf_N, issue_cf_N, project_cf_N, user_cf_N)
 * and the issue/custom field columns of the time entry lists, within the
 * custom fields' role visibility (A1-37).
 *
 * @return array{project: Project, tracker: Tracker, viewer: User, insider: User, insiderRole: Role, admin: User}
 */
function teCfFixture(): array
{
    $project = Project::factory()->create(['name' => 'Timelog project']);
    $tracker = Tracker::factory()->create(['name' => 'Bug']);
    $project->trackers()->attach($tracker);
    $plain = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries', 'save_queries'], 'issues_visibility' => 'default']);
    $insiderRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_time_entries', 'save_queries'], 'issues_visibility' => 'all']);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($plain);
    $insider = User::factory()->create();
    Member::factory()->for($project)->for($insider)->create()->roles()->attach($insiderRole);

    return ['project' => $project->fresh(), 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole, 'admin' => User::factory()->admin()->create()];
}

function teCfIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

/**
 * Writes custom values as an administrator (setCustomFieldValues() keeps
 * only the fields the signed-in user may see).
 *
 * @param  array<int, mixed>  $values
 */
function teCfSet(object $model, array $values): void
{
    auth()->setUser(User::factory()->admin()->create());
    $model->setCustomFieldValues($values);
    auth()->forgetUser();
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function teCfFilter(Project $project, User $viewer, string $key, string $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(TimeEntryFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(TimeEntry::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('the entry, issue and project custom fields filter the entries', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer] = teCfFixture();
    $entryField = CustomField::factory()->create(['customized_type' => CustomizableType::TimeEntry, 'name' => 'Billable', 'is_filter' => true]);
    $issueField = CustomField::factory()->create(['name' => 'Severity', 'is_filter' => true]);
    $issueField->trackers()->attach($tracker);
    $projectField = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region', 'is_filter' => true]);
    $critical = teCfIssue($project, $tracker);
    teCfSet($critical, [$issueField->id => 'critical']);
    teCfSet($project, [$projectField->id => 'emea']);
    $a = TimeEntry::factory()->for($project)->create(['issue_id' => $critical->id]);
    $b = TimeEntry::factory()->for($project)->create();
    teCfSet($b, [$entryField->id => 'yes']);

    $keys = TimeEntryFilterFieldRegistry::forProject($project, $viewer)->keys();

    expect($keys)->toContain("cf_{$entryField->id}", "issue_cf_{$issueField->id}", "project_cf_{$projectField->id}")
        ->and(teCfFilter($project, $viewer, "cf_{$entryField->id}", '~', ['yes']))->toBe([$b->id])
        ->and(teCfFilter($project, $viewer, "issue_cf_{$issueField->id}", '~', ['CRIT']))->toBe([$a->id])
        ->and(teCfFilter($project, $viewer, "issue_cf_{$issueField->id}", '!~', ['crit']))->toBe([])
        ->and(teCfFilter($project, $viewer, "project_cf_{$projectField->id}", '=', ['emea']))->toBe([$a->id, $b->id])
        ->and(teCfFilter($project, $viewer, "project_cf_{$projectField->id}", '!~', ['emea']))->toBe([]);
});

test('an issue custom field filter never matches through an issue the viewer cannot see', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider] = teCfFixture();
    $issueField = CustomField::factory()->create(['name' => 'Severity', 'is_filter' => true]);
    $issueField->trackers()->attach($tracker);
    $private = teCfIssue($project, $tracker, ['is_private' => true]);
    teCfSet($private, [$issueField->id => 'critical']);
    $entry = TimeEntry::factory()->for($project)->create(['issue_id' => $private->id]);

    expect(teCfFilter($project, $viewer, "issue_cf_{$issueField->id}", '~', ['critical']))->toBe([])
        ->and(teCfFilter($project, $insider, "issue_cf_{$issueField->id}", '~', ['critical']))->toBe([$entry->id]);
});

test('role-restricted custom fields are neither offered nor applied, and user fields are for admins only', function () {
    ['project' => $project, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole, 'admin' => $admin] = teCfFixture();
    $secretEntry = CustomField::factory()->create(['customized_type' => CustomizableType::TimeEntry, 'name' => 'Rate', 'is_filter' => true]);
    $secretEntry->roles()->attach($insiderRole);
    $secretProject = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Budget', 'is_filter' => true]);
    $secretProject->roles()->attach($insiderRole);
    $userField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $insider->id]);
    teCfSet($entry, [$secretEntry->id => 'secret-rate']);
    teCfSet($insider, [$userField->id => 'blue']);

    $viewerKeys = TimeEntryFilterFieldRegistry::forProject($project, $viewer)->keys();
    $insiderKeys = TimeEntryFilterFieldRegistry::forProject($project, $insider)->keys();

    expect($viewerKeys)->not->toContain("cf_{$secretEntry->id}")
        ->and($viewerKeys)->not->toContain("project_cf_{$secretProject->id}")
        ->and($viewerKeys)->not->toContain("user_cf_{$userField->id}")
        ->and(teCfFilter($project, $viewer, "cf_{$secretEntry->id}", '~', ['secret']))->toBe([$entry->id])
        ->and($insiderKeys)->toContain("cf_{$secretEntry->id}", "project_cf_{$secretProject->id}")
        ->and(teCfFilter($project, $insider, "cf_{$secretEntry->id}", '~', ['secret']))->toBe([$entry->id])
        ->and(teCfFilter($project, $admin, "user_cf_{$userField->id}", '=', ['blue']))->toBe([$entry->id])
        ->and(teCfFilter($project, $admin, "user_cf_{$userField->id}", '!~', ['blue']))->toBe([]);
});

test('the issue columns show the visible issue attributes only', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer] = teCfFixture();
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);
    $parent = teCfIssue($project, $tracker, ['subject' => 'Parent issue']);
    $visible = teCfIssue($project, $tracker, ['parent_id' => $parent->id, 'category_id' => $category->id]);
    $private = teCfIssue($project, $tracker, ['is_private' => true, 'category_id' => $category->id]);
    TimeEntry::factory()->for($project)->create(['issue_id' => $visible->id, 'comments' => 'on visible']);
    $hidden = TimeEntry::factory()->for($project)->create(['issue_id' => $private->id, 'comments' => 'on private']);

    $list = Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])
        ->set('columns', ['comments', 'issue_tracker', 'issue_parent', 'issue_status', 'issue_category']);

    $list->assertSee('課題のトラッカー')->assertSee("#{$parent->id} Parent issue")->assertSee('Backend');
    expect($list->instance()->columnValue($hidden->load('issue'), 'issue_category'))->toBe('')
        ->and($list->instance()->columnValue($hidden, 'issue_tracker'))->toBe('');
});

test('the custom field columns show values only where the viewer may see the field', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole] = teCfFixture();
    $issueField = CustomField::factory()->create(['name' => 'Severity', 'field_format' => CustomFieldFormat::String]);
    $issueField->trackers()->attach($tracker);
    $secretIssueField = CustomField::factory()->create(['name' => 'Internal']);
    $secretIssueField->trackers()->attach($tracker);
    $secretIssueField->roles()->attach($insiderRole);
    $projectField = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region']);
    $issue = teCfIssue($project, $tracker);
    teCfSet($issue, [$issueField->id => 'SEV-HIGH', $secretIssueField->id => 'INTERNAL-NOTE']);
    teCfSet($project, [$projectField->id => 'REGION-EMEA']);
    TimeEntry::factory()->for($project)->create(['issue_id' => $issue->id]);
    $columns = ['spent_on', "issue_cf_{$issueField->id}", "issue_cf_{$secretIssueField->id}", "project_cf_{$projectField->id}"];

    Livewire::actingAs($viewer)->test('time-entries.index', ['project' => $project])->set('columns', $columns)
        ->assertSee('SEV-HIGH')->assertSee('REGION-EMEA')->assertDontSee('INTERNAL-NOTE');
    Livewire::actingAs($viewer)->test('time-entries.global-index')->set('columns', $columns)
        ->assertSee('SEV-HIGH')->assertDontSee('INTERNAL-NOTE');
    Livewire::actingAs($insider)->test('time-entries.index', ['project' => $project])->set('columns', $columns)
        ->assertSee('INTERNAL-NOTE');
});
