<?php

use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\ImportStatus;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueImport;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Models\WorkflowFieldRule;
use App\Models\WorkflowTransition;
use App\Services\IncomingMailService;
use App\Support\Issues\IssueFieldRules;
use App\Support\Mail\ParsedIncomingMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/**
 * A project with one tracker (starting in $status), a default priority and a
 * member whose role holds $permissions, with the workflow's field rules
 * $rules (field => rule) for that role in the starting status.
 *
 * @param  array<int, string>  $permissions
 * @param  array<string, string>  $rules
 * @return array{project: Project, tracker: Tracker, status: IssueStatus, priority: Enumeration, role: Role, user: User}
 */
function fieldRulesSetup(array $permissions, array $rules = [], array $trackerAttributes = []): array
{
    $status = IssueStatus::factory()->create(['position' => 1]);
    $tracker = Tracker::factory()->create(['default_status_id' => $status->id, ...$trackerAttributes]);
    $project = Project::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $role = Role::factory()->create(['permissions' => ['view_issues', ...$permissions], 'assignable' => true]);
    $user = User::factory()->create(['email' => 'sender@example.com']);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    foreach ($rules as $field => $rule) {
        fieldRule($tracker, $role, $status, $field, $rule);
    }

    return compact('project', 'tracker', 'status', 'priority', 'role', 'user');
}

function fieldRule(Tracker $tracker, Role $role, IssueStatus $status, string $field, string $rule): void
{
    WorkflowFieldRule::create([
        'tracker_id' => $tracker->id, 'role_id' => $role->id, 'status_id' => $status->id,
        'field_name' => $field, 'rule' => $rule,
    ]);
}

function fieldRulesIssue(array $setup, array $attributes = []): Issue
{
    return Issue::factory()->for($setup['project'])->create([
        'tracker_id' => $setup['tracker']->id,
        'status_id' => $setup['status']->id,
        'priority_id' => $setup['priority']->id,
        ...$attributes,
    ]);
}

function fieldRulesCustomField(Tracker $tracker, array $attributes = []): CustomField
{
    $field = CustomField::factory()->create([
        'customized_type' => CustomizableType::Issue,
        'field_format' => CustomFieldFormat::String,
        'editable' => true,
        ...$attributes,
    ]);
    $field->trackers()->attach($tracker);

    return $field;
}

// --- REST API ----------------------------------------------------------------

test('the api ignores a field the workflow makes read-only on update', function () {
    $setup = fieldRulesSetup(['edit_issues'], ['due_date' => 'read_only']);
    $issue = fieldRulesIssue($setup, ['subject' => 'Before', 'due_date' => '2026-10-01']);

    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'After', 'due_date' => '2027-01-01'])->assertOk();

    expect($issue->fresh()->subject)->toBe('After')
        ->and($issue->fresh()->due_date->toDateString())->toBe('2026-10-01');
});

test('the api answers 422 when an update leaves a required field blank', function () {
    $setup = fieldRulesSetup(['edit_issues'], ['due_date' => 'required']);
    $issue = fieldRulesIssue($setup, ['subject' => 'Before', 'due_date' => '2026-10-01']);

    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['due_date' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Only the subject'])->assertOk();

    expect($issue->fresh()->due_date->toDateString())->toBe('2026-10-01');
});

test('the api checks the rules of the status the issue moves to', function () {
    $setup = fieldRulesSetup(['edit_issues']);
    $resolved = IssueStatus::factory()->create(['position' => 2]);
    WorkflowTransition::create(['tracker_id' => $setup['tracker']->id, 'role_id' => $setup['role']->id, 'old_status_id' => $setup['status']->id, 'new_status_id' => $resolved->id]);
    fieldRule($setup['tracker'], $setup['role'], $resolved, 'due_date', 'required');
    fieldRule($setup['tracker'], $setup['role'], $resolved, 'subject', 'read_only');
    $issue = fieldRulesIssue($setup, ['subject' => 'Before']);

    Passport::actingAs($setup['user']);

    $this->putJson("/api/v1/issues/{$issue->id}", ['status_id' => $resolved->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['due_date']);
    expect($issue->fresh()->status_id)->toBe($setup['status']->id);

    $this->putJson("/api/v1/issues/{$issue->id}", ['status_id' => $resolved->id, 'due_date' => '2026-12-01', 'subject' => 'Changed'])->assertOk();

    expect($issue->fresh()->status_id)->toBe($resolved->id)
        ->and($issue->fresh()->subject)->toBe('Before');
});

test('the api ignores a core field the tracker disables', function () {
    $setup = fieldRulesSetup(['add_issues', 'edit_issues'], [], ['disabled_core_fields' => ['category_id', 'estimated_hours']]);
    $category = IssueCategory::factory()->for($setup['project'])->create();
    $issue = fieldRulesIssue($setup);

    Passport::actingAs($setup['user']);

    $created = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", [
        'tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'New', 'category_id' => $category->id, 'estimated_hours' => 3,
    ])->assertCreated();
    $this->putJson("/api/v1/issues/{$issue->id}", ['category_id' => $category->id, 'estimated_hours' => 5])->assertOk();

    $new = Issue::query()->findOrFail($created->json('data.id'));

    expect($new->category_id)->toBeNull()->and($new->estimated_hours)->toBeNull()
        ->and($issue->fresh()->category_id)->toBeNull()->and($issue->fresh()->estimated_hours)->toBeNull();
});

test('the api applies the rules of the starting status when creating', function () {
    $setup = fieldRulesSetup(['add_issues'], ['start_date' => 'required', 'description' => 'read_only']);

    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", [
        'tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'No start date',
    ])->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
    expect(Issue::query()->where('subject', 'No start date')->exists())->toBeFalse();

    $created = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", [
        'tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'With start date', 'start_date' => '2026-10-01', 'description' => 'Not mine to set',
    ])->assertCreated();

    expect(Issue::query()->findOrFail($created->json('data.id'))->description)->toBeNull();
});

test('the api applies read-only and required rules to custom fields', function () {
    $setup = fieldRulesSetup(['add_issues', 'edit_issues']);
    $locked = fieldRulesCustomField($setup['tracker']);
    $needed = fieldRulesCustomField($setup['tracker']);
    fieldRule($setup['tracker'], $setup['role'], $setup['status'], "cf_{$locked->id}", 'read_only');
    fieldRule($setup['tracker'], $setup['role'], $setup['status'], "cf_{$needed->id}", 'required');

    Passport::actingAs($setup['user']);

    $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", [
        'tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'Missing', 'custom_fields' => [['id' => $locked->id, 'value' => 'x']],
    ])->assertUnprocessable()->assertJsonValidationErrors(["custom_fields.{$needed->id}"]);

    $created = $this->postJson("/api/v1/projects/{$setup['project']->id}/issues", [
        'tracker_id' => $setup['tracker']->id, 'priority_id' => $setup['priority']->id, 'subject' => 'Given',
        'custom_fields' => [['id' => $locked->id, 'value' => 'x'], ['id' => $needed->id, 'value' => 'y']],
    ])->assertCreated();
    $issue = Issue::query()->findOrFail($created->json('data.id'));

    expect($issue->customValue($locked))->toBeNull()
        ->and($issue->customValue($needed))->toBe('y');

    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $needed->id, 'value' => '']]])
        ->assertUnprocessable()->assertJsonValidationErrors(["custom_fields.{$needed->id}"]);
});

test('the api rules do not apply to a caller who may only add notes, nor to administrators', function () {
    $setup = fieldRulesSetup(['add_issue_notes'], ['due_date' => 'required']);
    $issue = fieldRulesIssue($setup);

    Passport::actingAs($setup['user']);
    $this->putJson("/api/v1/issues/{$issue->id}", ['notes' => 'Just a comment'])->assertOk();

    Passport::actingAs(User::factory()->admin()->create());
    $this->putJson("/api/v1/issues/{$issue->id}", ['subject' => 'Admin edit'])->assertOk();

    expect($issue->fresh()->subject)->toBe('Admin edit')
        ->and($issue->journals()->whereNotNull('notes')->where('notes', 'Just a comment')->exists())->toBeTrue();
});

// --- Issue form ----------------------------------------------------------------

test('the issue form does not save a read-only field set by a crafted request', function () {
    $setup = fieldRulesSetup(['edit_issues'], ['due_date' => 'read_only', 'subject' => 'read_only']);
    $issue = fieldRulesIssue($setup, ['subject' => 'Locked', 'due_date' => '2026-10-01']);

    Livewire::actingAs($setup['user'])
        ->test('issues.form', ['project' => $setup['project'], 'issue' => $issue])
        ->set('subject', 'Tampered')
        ->set('due_date', '2027-01-01')
        ->set('description', 'Allowed')
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->subject)->toBe('Locked')
        ->and($issue->fresh()->due_date->toDateString())->toBe('2026-10-01')
        ->and($issue->fresh()->description)->toBe('Allowed');
});

test('the issue form does not save a field the tracker disables', function () {
    $setup = fieldRulesSetup(['add_issues', 'edit_issues'], [], ['disabled_core_fields' => ['estimated_hours']]);
    $issue = fieldRulesIssue($setup);

    Livewire::actingAs($setup['user'])
        ->test('issues.form', ['project' => $setup['project'], 'issue' => $issue])
        ->set('estimated_hours', '8')
        ->call('save')
        ->assertHasNoErrors();

    expect($issue->fresh()->estimated_hours)->toBeNull();
});

test('the new issue form applies the rules of the starting status', function () {
    $setup = fieldRulesSetup(['add_issues'], ['due_date' => 'required']);

    Livewire::actingAs($setup['user'])
        ->test('issues.form', ['project' => $setup['project']])
        ->set('subject', 'New one')
        ->call('save')
        ->assertHasErrors(['due_date']);

    expect(Issue::query()->where('subject', 'New one')->exists())->toBeFalse();
});

test('the issue form requires a required field only when it can be filled', function () {
    $setup = fieldRulesSetup(['edit_issues'], ['category_id' => 'required']);
    $issue = fieldRulesIssue($setup);

    // No category in the project: nothing to choose, so not required.
    Livewire::actingAs($setup['user'])
        ->test('issues.form', ['project' => $setup['project'], 'issue' => $issue])
        ->set('subject', 'Saved')
        ->call('save')
        ->assertHasNoErrors();

    IssueCategory::factory()->for($setup['project'])->create();

    Livewire::actingAs($setup['user'])
        ->test('issues.form', ['project' => $setup['project'], 'issue' => $issue->fresh()])
        ->call('save')
        ->assertHasErrors(['category_id']);
});

// --- Bulk edit / context menu --------------------------------------------------

test('bulk edit leaves read-only fields and skips issues whose required field would be blank', function () {
    $setup = fieldRulesSetup(['edit_issues']);
    $other = Tracker::factory()->create(['default_status_id' => $setup['status']->id]);
    $setup['project']->trackers()->attach($other);
    fieldRule($setup['tracker'], $setup['role'], $setup['status'], 'due_date', 'read_only');
    fieldRule($other, $setup['role'], $setup['status'], 'assigned_to_id', 'required');

    $locked = fieldRulesIssue($setup, ['due_date' => '2026-10-01']);
    $needsAssignee = fieldRulesIssue($setup, ['tracker_id' => $other->id, 'due_date' => '2026-10-01']);
    $assigned = fieldRulesIssue($setup, ['tracker_id' => $other->id, 'due_date' => '2026-10-01', 'assigned_to_id' => $setup['user']->id]);

    Livewire::actingAs($setup['user'])
        ->test('issues.index', ['project' => $setup['project']])
        ->set('selected', [$locked->id, $needsAssignee->id, $assigned->id])
        ->set('bulkDueDate', '2027-02-02')
        ->call('applyBulkEdit')
        ->assertHasNoErrors();

    expect($locked->fresh()->due_date->toDateString())->toBe('2026-10-01')
        ->and($needsAssignee->fresh()->due_date->toDateString())->toBe('2026-10-01')
        ->and($needsAssignee->journals()->exists())->toBeFalse()
        ->and($assigned->fresh()->due_date->toDateString())->toBe('2027-02-02');
});

test('the context menu cannot change a field the workflow makes read-only', function () {
    $setup = fieldRulesSetup(['edit_issues'], ['priority_id' => 'read_only']);
    $issue = fieldRulesIssue($setup);
    $priority = Enumeration::factory()->create();

    Livewire::actingAs($setup['user'])
        ->test('issues.index', ['project' => $setup['project']])
        ->call('openContextMenu', $issue->id)
        ->call('contextUpdate', 'priority_id', (string) $priority->id);

    expect($issue->fresh()->priority_id)->toBe($setup['priority']->id);
});

// --- CSV import ---------------------------------------------------------------

test('the csv import ignores read-only fields and fails a row missing a required field', function () {
    Storage::fake('local');

    $setup = fieldRulesSetup(['add_issues', 'import_issues'], ['due_date' => 'read_only', 'start_date' => 'required']);
    $csv = "subject,start,due\nHas start,2026-10-01,2026-12-31\nNo start,,2026-12-31\n";

    Livewire::actingAs($setup['user'])
        ->test('issues.import', ['project' => $setup['project']])
        ->set('csvFile', UploadedFile::fake()->createWithContent('issues.csv', $csv))
        ->set('mapping.subject', 'subject')
        ->set('mapping.start_date', 'start')
        ->set('mapping.due_date', 'due')
        ->call('startImport');

    $import = IssueImport::query()->firstOrFail();
    $imported = Issue::query()->where('subject', 'Has start')->firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(1)
        ->and($imported->due_date)->toBeNull()
        ->and(Issue::query()->where('subject', 'No start')->exists())->toBeFalse();
});

// --- Incoming mail ------------------------------------------------------------

test('incoming mail ignores read-only keywords and rejects a mail missing a required field', function () {
    Setting::set('mail_handler_allow_override', 'all');
    $setup = fieldRulesSetup(['add_issues', 'edit_issues'], ['due_date' => 'read_only', 'start_date' => 'required']);
    Setting::set('incoming_mail_default_project_id', $setup['project']->id);
    Setting::set('incoming_mail_default_tracker_id', $setup['tracker']->id);
    Setting::set('incoming_mail_default_status_id', $setup['status']->id);

    $service = app(IncomingMailService::class);

    expect($service->createIssueFromMail(new ParsedIncomingMail(subject: 'No start', body: 'Due date: 2026-12-31', fromEmail: 'sender@example.com')))->toBeNull()
        ->and(Issue::query()->where('subject', 'No start')->exists())->toBeFalse();

    $issue = $service->createIssueFromMail(new ParsedIncomingMail(subject: 'With start', body: "Start date: 2026-10-01\nDue date: 2026-12-31", fromEmail: 'sender@example.com'));

    expect($issue)->not->toBeNull()
        ->and($issue->start_date->toDateString())->toBe('2026-10-01')
        ->and($issue->due_date)->toBeNull();

    $reply = $service->createIssueFromMail(new ParsedIncomingMail(subject: "Re: [#{$issue->id}]", body: "Due date: 2027-01-01\n\nThanks", fromEmail: 'sender@example.com'));

    expect($reply)->not->toBeNull()
        ->and($issue->fresh()->due_date)->toBeNull();
});

test('a read-only project_id is dropped for an existing issue but a new issue can always set its project (A17-14)', function () {
    $setup = fieldRulesSetup(['edit_issues', 'add_issues'], ['project_id' => 'read_only']);
    $other = Project::factory()->create();
    $issue = fieldRulesIssue($setup);

    [$kept] = IssueFieldRules::filterInput($issue, ['project_id' => $other->id, 'subject' => 'Moved?'], [], $setup['user']);

    expect($kept)->not->toHaveKey('project_id')->and($kept)->toHaveKey('subject');

    $new = new Issue(['tracker_id' => $setup['tracker']->id, 'status_id' => $setup['status']->id, 'project_id' => $setup['project']->id]);
    [$keptForNew] = IssueFieldRules::filterInput($new, ['project_id' => $setup['project']->id], [], $setup['user']);

    expect($keptForNew)->toHaveKey('project_id');
});

test('assignee-only transitions follow the assignee before the change, not the one being set (A17-21)', function () {
    $setup = fieldRulesSetup(['edit_issues']);
    $resolved = IssueStatus::factory()->create(['position' => 2]);
    WorkflowTransition::create(['tracker_id' => $setup['tracker']->id, 'role_id' => $setup['role']->id, 'old_status_id' => $setup['status']->id, 'new_status_id' => $resolved->id, 'assignee' => true]);
    $colleague = User::factory()->create();
    Member::factory()->for($setup['project'])->for($colleague)->create()->roles()->attach($setup['role']);

    // The current assignee may reassign and move the status in one request ...
    $mine = fieldRulesIssue($setup, ['assigned_to_id' => $setup['user']->id]);
    Passport::actingAs($setup['user']);
    $this->putJson("/api/v1/issues/{$mine->id}", ['status_id' => $resolved->id, 'assigned_to_id' => $colleague->id])->assertOk();
    expect($mine->fresh()->status_id)->toBe($resolved->id);

    // ... but taking an issue over does not grant the assignee's transition in the same request.
    $theirs = fieldRulesIssue($setup, ['assigned_to_id' => $colleague->id]);
    $this->putJson("/api/v1/issues/{$theirs->id}", ['status_id' => $resolved->id, 'assigned_to_id' => $setup['user']->id])->assertForbidden();
    expect($theirs->fresh()->status_id)->toBe($setup['status']->id);
});

test('the issue form locks the fields a workflow makes read-only, including the newly listed ones (A17-19)', function () {
    $setup = fieldRulesSetup(['edit_issues', 'add_issues', 'manage_subtasks'], ['estimated_hours' => 'read_only', 'done_ratio' => 'read_only', 'is_private' => 'read_only', 'parent_issue_id' => 'read_only']);
    $issue = fieldRulesIssue($setup);

    $html = Livewire::actingAs($setup['user'])->test('issues.form', ['project' => $setup['project'], 'issue' => $issue])->html();

    expect($html)->toMatch('/id="field-estimated_hours"[^>]*disabled/')
        ->and($html)->toMatch('/id="field-done_ratio"[^>]*disabled/')
        ->and($html)->toMatch('/id="field-parent_id"[^>]*disabled/');
});
