<?php

use App\Enums\CustomizableType;
use App\Enums\QueryType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * A1-37: an issue custom field restricted to some roles is offered as a
 * filter/column only where the viewer holds one of them, and its value is
 * treated as absent on the rows of other projects (Redmine's
 * CustomField.visible / visibility_by_project_condition).
 *
 * The fixture: `parent` and its subproject `child`; the field "Secret" is
 * visible to the role `insider` only. `insider` holds that role in parent
 * but only a plain role in child; `outsider` has the plain role in parent.
 *
 * @return array{parent: Project, child: Project, field: CustomField, insider: User, outsider: User, admin: User, secretParent: Issue, plainParent: Issue, secretChild: Issue, insiderRole: Role}
 */
function cfVisibilityFixture(): array
{
    $parent = Project::factory()->create(['identifier' => 'cfvis-parent']);
    $child = Project::factory()->create(['identifier' => 'cfvis-child', 'parent_id' => $parent->id]);
    $tracker = Tracker::factory()->create();
    $parent->trackers()->attach($tracker);
    $child->trackers()->attach($tracker);

    $insiderRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'save_queries', 'view_time_entries'], 'issues_visibility' => 'all']);
    $plainRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'save_queries', 'view_time_entries'], 'issues_visibility' => 'all']);

    $field = CustomField::factory()->create(['name' => 'Secret', 'is_filter' => true]);
    $field->trackers()->attach($tracker);
    $field->roles()->attach($insiderRole);

    $insider = User::factory()->create();
    Member::factory()->for($parent)->for($insider)->create()->roles()->attach($insiderRole);
    Member::factory()->for($child)->for($insider)->create()->roles()->attach($plainRole);

    $outsider = User::factory()->create();
    Member::factory()->for($parent)->for($outsider)->create()->roles()->attach($plainRole);

    $issue = fn (Project $project, string $subject, ?string $secret) => tap(Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
    ]), fn (Issue $created) => $secret !== null ? $created->setCustomFieldValues([$field->id => $secret]) : null);

    // Values are written as an administrator: setCustomFieldValues() only
    // stores the fields the signed-in user may see.
    $admin = User::factory()->admin()->create();
    auth()->setUser($admin);
    $issues = [
        'secretParent' => $issue($parent, 'Parent secret', 'classified-a'),
        'plainParent' => $issue($parent, 'Parent plain', 'ordinary'),
        'secretChild' => $issue($child, 'Child secret', 'classified-b'),
    ];
    auth()->forgetUser();

    return [
        'parent' => $parent->fresh(),
        'child' => $child->fresh(),
        'field' => $field,
        'insider' => $insider,
        'outsider' => $outsider,
        'admin' => $admin,
        ...$issues,
        'insiderRole' => $insiderRole,
    ];
}

/**
 * @return array<string, array<int, mixed>>
 */
function cfVisibilityFilter(CustomField $field, string $value = 'classified'): array
{
    return [
        'activeFilterKeys' => ["cf_{$field->id}"],
        'filterOperators' => ["cf_{$field->id}" => '~'],
        'filterValues' => ["cf_{$field->id}" => [$value]],
    ];
}

/**
 * @param  array<string, mixed>  $state
 * @return array<int, string>
 */
function cfVisibilityListSubjects(User $user, string $component, array $state, array $parameters = []): array
{
    $list = Livewire::actingAs($user)->test($component, $parameters)->set('statusFilter', 'all');

    foreach ($state as $property => $value) {
        $list->set($property, $value);
    }

    return $list->get('issues')->getCollection()->pluck('subject')->sort()->values()->all();
}

test('the filter is offered only to a viewer holding one of its roles, and always to an administrator', function () {
    ['parent' => $parent, 'field' => $field, 'insider' => $insider, 'outsider' => $outsider, 'admin' => $admin] = cfVisibilityFixture();
    $key = "cf_{$field->id}";

    expect(IssueFilterFieldRegistry::forProject($parent, $outsider))->not->toHaveKey($key)
        ->and(IssueFilterFieldRegistry::forProjects(collect([$parent]), $outsider))->not->toHaveKey($key)
        ->and(IssueFilterFieldRegistry::forProject($parent, $insider))->toHaveKey($key)
        ->and(IssueFilterFieldRegistry::forProject($parent, $admin))->toHaveKey($key);

    $columns = Livewire::actingAs($outsider)->test('issues.index', ['project' => $parent])->get('availableColumns');

    expect($columns)->not->toHaveKey($key);
});

test('a hidden custom field filter sent to the project list is ignored', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider, 'insider' => $insider] = cfVisibilityFixture();

    expect(cfVisibilityListSubjects($outsider, 'issues.index', cfVisibilityFilter($field), ['project' => $parent]))->toBe(['Parent plain', 'Parent secret'])
        ->and(cfVisibilityListSubjects($insider, 'issues.index', cfVisibilityFilter($field), ['project' => $parent]))->toBe(['Parent secret']);
});

test('a hidden custom field column shows nothing in the list, the CSV and the PDF', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider, 'secretParent' => $secretParent] = cfVisibilityFixture();
    $key = "cf_{$field->id}";

    $list = Livewire::actingAs($outsider)->test('issues.index', ['project' => $parent])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', $key]);
    $loaded = $list->get('issues')->getCollection()->firstWhere('id', $secretParent->id);

    expect($list->instance()->columnValue($loaded, $key))->toBe('');
    $list->assertDontSee('classified-a');

    $csv = $list->call('exportCsv');
    expect(base64_decode($csv->effects['download']['content']))->toContain('Parent secret')->not->toContain('classified-a');

    $pdfRows = null;
    View::composer('pdf.issues', function ($view) use (&$pdfRows) {
        $pdfRows = $view->getData()['rows'];
    });
    $list->call('exportPdf');

    expect(json_encode($pdfRows))->toContain('Parent secret')->not->toContain('classified-a');
});

test('sorting and grouping by a hidden custom field do nothing for a viewer without its role', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider] = cfVisibilityFixture();
    $key = "cf_{$field->id}";

    $list = Livewire::actingAs($outsider)->test('issues.index', ['project' => $parent])
        ->set('statusFilter', 'all')
        ->set('groupBy', $key);

    expect($list->get('groupTotals'))->toBeEmpty()
        ->and($list->instance()->sortableColumns())->not->toHaveKey($key);
});

test('on a list with subprojects the value counts as absent where the viewer lacks the role', function () {
    Setting::set('display_subprojects_issues', true);
    ['parent' => $parent, 'field' => $field, 'insider' => $insider, 'admin' => $admin, 'secretChild' => $secretChild] = cfVisibilityFixture();
    $key = "cf_{$field->id}";

    expect(cfVisibilityListSubjects($insider, 'issues.index', cfVisibilityFilter($field), ['project' => $parent]))->toBe(['Parent secret'])
        ->and(cfVisibilityListSubjects($admin, 'issues.index', cfVisibilityFilter($field), ['project' => $parent]))->toBe(['Child secret', 'Parent secret']);

    $list = Livewire::actingAs($insider)->test('issues.index', ['project' => $parent])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', $key]);
    $loaded = $list->get('issues')->getCollection()->firstWhere('id', $secretChild->id);

    expect($list->instance()->columnValue($loaded, $key))->toBe('');

    $grouped = $list->set('groupBy', $key)->get('issues')->getCollection()->pluck('subject')->all();
    expect($grouped)->not->toContain('Child secret');

    $sorted = Livewire::actingAs($insider)->test('issues.index', ['project' => $parent])
        ->set('statusFilter', 'all')
        ->set('sortKey', $key)->set('sortDirection', 'asc')
        ->get('issues')->getCollection()->pluck('subject')->all();

    // Blank sorts first; readable, classified-b would sort between the two.
    expect($sorted)->toBe(['Child secret', 'Parent secret', 'Parent plain']);
});

test('the cross-project list limits the filter and the column to projects where the field is visible', function () {
    ['field' => $field, 'insider' => $insider, 'outsider' => $outsider, 'admin' => $admin, 'secretChild' => $secretChild] = cfVisibilityFixture();
    $key = "cf_{$field->id}";

    expect(cfVisibilityListSubjects($insider, 'issues.global-index', cfVisibilityFilter($field)))->toBe(['Parent secret'])
        ->and(cfVisibilityListSubjects($outsider, 'issues.global-index', cfVisibilityFilter($field)))->toBe(['Parent plain', 'Parent secret'])
        ->and(cfVisibilityListSubjects($admin, 'issues.global-index', cfVisibilityFilter($field)))->toBe(['Child secret', 'Parent secret']);

    $list = Livewire::actingAs($insider)->test('issues.global-index')->set('statusFilter', 'all')->set('columns', ['subject', $key]);
    $loaded = $list->get('issues')->getCollection()->firstWhere('id', $secretChild->id);

    expect($list->instance()->columnValue($loaded, $key))->toBe('');
    $list->assertDontSee('classified-b')->assertSee('classified-a');
});

test('a saved query holding a hidden custom field filter loads and runs without it', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider] = cfVisibilityFixture();
    $filters = ["cf_{$field->id}" => ['operator' => '~', 'values' => ['classified']]];
    $projectQuery = SavedQuery::create(['name' => 'Project', 'type' => QueryType::Issue->value, 'user_id' => $outsider->id, 'project_id' => $parent->id, 'visibility' => 'private', 'filters' => $filters, 'column_names' => ['subject', "cf_{$field->id}"]]);
    $globalQuery = SavedQuery::create(['name' => 'Global', 'type' => QueryType::Issue->value, 'user_id' => $outsider->id, 'project_id' => null, 'visibility' => 'private', 'filters' => $filters, 'column_names' => ['subject', "cf_{$field->id}"]]);

    $projectList = Livewire::actingAs($outsider)->test('issues.index', ['project' => $parent])->call('loadQuery', $projectQuery->id)->set('statusFilter', 'all');
    $globalList = Livewire::actingAs($outsider)->test('issues.global-index')->call('loadQuery', $globalQuery->id)->set('statusFilter', 'all');

    expect($projectList->get('issues')->getCollection()->pluck('subject')->sort()->values()->all())->toBe(['Parent plain', 'Parent secret'])
        ->and($globalList->get('issues')->getCollection()->pluck('subject')->sort()->values()->all())->toBe(['Parent plain', 'Parent secret']);
    $projectList->assertDontSee('classified-a');
    $globalList->assertDontSee('classified-a');
});

test('the Atom feed ignores a hidden custom field filter in its URL', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider, 'insider' => $insider] = cfVisibilityFixture();
    $query = http_build_query(['statusFilter' => 'all', ...cfVisibilityFilter($field)]);

    $this->actingAs($outsider)->get(route('issues.atom', $parent).'?'.$query)->assertOk()
        ->assertSee('Parent secret')->assertSee('Parent plain');
    $this->actingAs($insider)->get(route('issues.atom', $parent).'?'.$query)->assertOk()
        ->assertSee('Parent secret')->assertDontSee('Parent plain');
});

test('a My page block of a saved query ignores its hidden custom field filter', function () {
    ['parent' => $parent, 'field' => $field, 'outsider' => $outsider, 'plainParent' => $plainParent] = cfVisibilityFixture();
    $savedQuery = SavedQuery::create(['name' => 'Block', 'type' => QueryType::Issue->value, 'user_id' => $outsider->id, 'project_id' => $parent->id, 'visibility' => 'private', 'filters' => ["cf_{$field->id}" => ['operator' => '~', 'values' => ['classified']]], 'column_names' => ['subject']]);

    $rows = Livewire::actingAs($outsider)->test('my-page.index')
        ->call('addBlock', "issue_query:{$savedQuery->id}")
        ->instance()->blockRows("issue_query:{$savedQuery->id}");

    expect($rows->pluck('title')->join(' '))->toContain("#{$plainParent->id}");
});

test('the REST issue list ignores a hidden custom field filter, directly or through query_id', function () {
    ['parent' => $parent, 'field' => $field, 'insider' => $insider, 'outsider' => $outsider, 'admin' => $admin, 'secretParent' => $secretParent, 'plainParent' => $plainParent, 'secretChild' => $secretChild] = cfVisibilityFixture();
    $key = "cf_{$field->id}";
    $filter = "f[]={$key}&op[{$key}]=~&v[{$key}][]=classified";
    $ids = function (User $user, string $uri): array {
        app('auth')->forgetGuards();
        $response = $this->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])->getJson($uri)->assertOk();

        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    };
    $savedQuery = SavedQuery::create(['name' => 'Api', 'type' => QueryType::Issue->value, 'user_id' => $outsider->id, 'project_id' => $parent->id, 'visibility' => 'private', 'filters' => [$key => ['operator' => '~', 'values' => ['classified']]], 'column_names' => ['subject']]);

    expect($ids($outsider, "/api/v1/projects/{$parent->id}/issues?{$filter}"))->toBe([$secretParent->id, $plainParent->id])
        ->and($ids($outsider, "/api/v1/projects/{$parent->id}/issues?{$key}=~classified"))->toBe([$secretParent->id, $plainParent->id])
        ->and($ids($outsider, "/api/v1/projects/{$parent->id}/issues?query_id={$savedQuery->id}"))->toBe([$secretParent->id, $plainParent->id])
        ->and($ids($insider, "/api/v1/issues?{$filter}"))->toBe([$secretParent->id])
        ->and($ids($admin, "/api/v1/issues?{$filter}"))->toBe([$secretParent->id, $secretChild->id]);
});

test('a hidden time entry custom field column shows nothing', function () {
    ['parent' => $parent, 'outsider' => $outsider, 'insiderRole' => $insiderRole] = cfVisibilityFixture();
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::TimeEntry, 'name' => 'Rate code']);
    $field->roles()->attach($insiderRole);
    $entry = TimeEntry::factory()->for($parent)->create(['user_id' => $outsider->id]);
    auth()->setUser(User::factory()->admin()->create());
    $entry->setCustomFieldValues([$field->id => 'RATE-SECRET']);
    auth()->forgetUser();
    expect($entry->fresh()->customFieldValues)->toHaveCount(1);

    Livewire::actingAs($outsider)->test('time-entries.index', ['project' => $parent])
        ->set('columns', ['spent_on', "cf_{$field->id}"])
        ->assertDontSee('RATE-SECRET');
    Livewire::actingAs($outsider)->test('time-entries.global-index')
        ->set('columns', ['spent_on', "cf_{$field->id}"])
        ->assertDontSee('RATE-SECRET');
});
