<?php

use App\Enums\CustomizableType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * A15-11b: Redmine's IssueQuery#draw_selected_columns / gantts/_chart's
 * gantt_selected_column — the loaded issue query's chosen columns shown as
 * extra text next to each drawn issue row. Reuses RelatedIssueColumns (the
 * same compact, role-visibility-aware column set/renderer the issue
 * detail page's related-issues table already uses).
 */
function selectedColumnsViewer(Project $project, array $permissions = ['view_project', 'view_issues', 'view_gantt', 'save_queries']): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function selectedColumnsIssue(Project $project, Tracker $tracker, string $subject, string $priorityName = 'High'): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create(['name' => $priorityName])->id,
        'subject' => $subject,
        'start_date' => '2026-01-05',
        'due_date' => '2026-01-25',
    ]);
}

test('selected columns are hidden by default even with a loaded query', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project);
    $issue = selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');
    $query = SavedQuery::create([
        'name' => 'With priority column', 'type' => 'issue', 'user_id' => $viewer->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject', 'priority_id'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->call('loadQuery', $query->id)
        ->assertDontSee('Urgent');
});

test('turning on draw_selected_columns shows the loaded query\'s columns next to the row', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project);
    $issue = selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');
    $query = SavedQuery::create([
        'name' => 'With priority column', 'type' => 'issue', 'user_id' => $viewer->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject', 'priority_id'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->call('loadQuery', $query->id)
        ->set('drawSelectedColumns', true)
        ->assertSee('Urgent');
});

test('draw_selected_columns via query_id in the URL also loads the columns', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project);
    selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');
    $query = SavedQuery::create([
        'name' => 'With priority column', 'type' => 'issue', 'user_id' => $viewer->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => ['subject', 'priority_id'],
    ]);

    Livewire::withQueryParams(['query_id' => $query->id])
        ->actingAs($viewer)
        ->test('gantt.index', ['project' => $project])
        ->set('drawSelectedColumns', true)
        ->assertSee('Urgent');
});

test('a query with no columns of its own falls back to issue_list_default_columns, like the issue list itself', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project);
    selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');
    Setting::set('issue_list_default_columns', ['tracker_id', 'status_id', 'priority_id', 'subject', 'assigned_to_id']);
    $query = SavedQuery::create([
        'name' => 'Filters only, no columns', 'type' => 'issue', 'user_id' => $viewer->id,
        'project_id' => $project->id, 'visibility' => 'private',
        'filters' => [], 'column_names' => [],
    ]);

    $component = Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->call('loadQuery', $query->id);

    expect($component->get('columns'))->toBe(['status_id', 'priority_id', 'assigned_to_id']);
});

test('columns set to something other than a string is ignored rather than erroring', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project);
    selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');

    // columns isn't #[Url]-bound, but it is still a public Livewire
    // property a crafted request could set directly — selectedColumnTexts()
    // must sanitize it rather than pass a non-string key into
    // RelatedIssueColumns::relationsFor()/value() and blow up.
    Livewire::actingAs($viewer)->test('gantt.index', ['project' => $project])
        ->set('columns', [123, null, 'priority_id'])
        ->set('drawSelectedColumns', true)
        ->assertSee('Urgent');
});

test('a custom field column the viewer\'s role cannot see stays blank', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $field = CustomField::factory()->create(['name' => 'Secret field', 'customized_type' => CustomizableType::Issue->value]);
    $field->trackers()->attach($tracker);
    $privilegedRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_gantt', 'save_queries']]);
    $field->roles()->attach($privilegedRole);

    $privileged = User::factory()->create();
    Member::factory()->for($project)->for($privileged)->create()->roles()->attach($privilegedRole);

    $unprivileged = User::factory()->create();
    Member::factory()->for($project)->for($unprivileged)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'view_gantt', 'save_queries']])
    );

    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Has a secret',
        'start_date' => '2026-01-05',
        'due_date' => '2026-01-25',
    ]);
    // relevantCustomFields() defaults to auth()->user(), which is null
    // outside an actingAs() session — pass the field explicitly so the
    // value is written regardless of who (if anyone) is "signed in" at
    // this point in the test.
    $issue->setCustomFieldValues([(string) $field->id => 'Classified value'], collect([$field]));

    $query = SavedQuery::create([
        'name' => 'With secret column', 'type' => 'issue', 'user_id' => $privileged->id,
        'project_id' => $project->id, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject', "cf_{$field->id}"],
    ]);

    Livewire::actingAs($privileged)->test('gantt.index', ['project' => $project])
        ->call('loadQuery', $query->id)
        ->set('drawSelectedColumns', true)
        ->assertSee('Classified value');

    Livewire::actingAs($unprivileged)->test('gantt.index', ['project' => $project])
        ->call('loadQuery', $query->id)
        ->set('drawSelectedColumns', true)
        ->assertDontSee('Classified value');
});

test('the cross-project gantt also shows selected columns once turned on', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project, ['view_project', 'view_issues', 'view_gantt', 'save_queries']);
    selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');
    $query = SavedQuery::create([
        'name' => 'Global with priority column', 'type' => 'issue', 'user_id' => $viewer->id,
        'project_id' => null, 'visibility' => 'public',
        'filters' => [], 'column_names' => ['subject', 'priority_id'],
    ]);

    Livewire::actingAs($viewer)->test('gantt.global-index')
        ->call('loadQuery', $query->id)
        ->assertDontSee('Urgent')
        ->set('drawSelectedColumns', true)
        ->assertSee('Urgent');
});

test('selected columns render once per issue row and never on the project header row', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $viewer = selectedColumnsViewer($project, ['view_project', 'view_issues', 'view_gantt', 'save_queries']);
    selectedColumnsIssue($project, $tracker, 'Row one', 'Urgent');

    // The cross-project gantt always draws a project header row above its
    // issues; that row must never pick up an issue-column value just
    // because it shares the same label column layout as the issue rows
    // below it (Redmine's column_content_for_issue only ever fires for
    // an Issue object, never a Project/Version row).
    $html = Livewire::actingAs($viewer)->test('gantt.global-index')
        ->set('columns', ['priority_id'])
        ->set('drawSelectedColumns', true)
        ->html();

    expect(substr_count($html, 'data-gantt-selected-columns'))->toBe(1)
        ->and($html)->toContain('Urgent');
});
