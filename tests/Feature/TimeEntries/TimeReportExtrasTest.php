<?php

use App\Enums\CustomFieldFormat;
use App\Enums\EnumerationType;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function reportExtrasMember(Project $project, string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries', 'log_time'], 'time_entries_visibility' => $visibility]));

    return $user;
}

function reportExtrasEntry(Project $project, User $user, float $hours, ?Issue $issue = null): TimeEntry
{
    return TimeEntry::factory()->for($project)->create([
        'user_id' => $user->id, 'issue_id' => $issue?->id, 'hours' => $hours, 'spent_on' => '2026-03-10',
        'activity_id' => Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value])->id,
    ]);
}

test('a time entry list custom field can be a row axis and sums hours per value', function () {
    $project = Project::factory()->create();
    $user = reportExtrasMember($project);
    $field = CustomField::factory()->list(['Billable', 'Internal'])->create(['customized_type' => 'time_entry', 'name' => 'Billing']);
    reportExtrasEntry($project, $user, 2)->setCustomFieldValues([$field->id => 'Billable']);
    reportExtrasEntry($project, $user, 3)->setCustomFieldValues([$field->id => 'Billable']);
    reportExtrasEntry($project, $user, 1)->setCustomFieldValues([$field->id => 'Internal']);
    reportExtrasEntry($project, $user, 4);

    $page = Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->set('criteria', ["cf_time_{$field->id}"]);
    $rows = collect($page->get('report')->rows)->mapWithKeys(fn ($row) => [$row['labels'][0] => $row['total']]);

    expect($page->get('availableAxes'))->toHaveKey("cf_time_{$field->id}")
        ->and($rows->all())->toBe(['(なし)' => 4.0, 'Billable' => 5.0, 'Internal' => 1.0]);
});

test('an issue custom field can be a row axis through the entry\'s issue', function () {
    $project = Project::factory()->create();
    $user = reportExtrasMember($project);
    $field = CustomField::factory()->list(['A', 'B'])->create(['customized_type' => 'issue', 'name' => 'Team']);
    $tracker = Tracker::factory()->create();
    $field->trackers()->attach($tracker);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'status_id' => IssueStatus::factory()->create()->id, 'priority_id' => Enumeration::factory()->create()->id]);
    $issue->setCustomFieldValues([$field->id => 'B']);
    reportExtrasEntry($project, $user, 2, $issue);
    reportExtrasEntry($project, $user, 1);

    $page = Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->set('criteria', ["cf_issue_{$field->id}"]);
    $rows = collect($page->get('report')->rows)->mapWithKeys(fn ($row) => [$row['labels'][0] => $row['total']]);

    expect($rows->all())->toBe(['(なし)' => 1.0, 'B' => 2.0]);
});

test('a boolean custom field axis labels yes and no and keeps unset apart', function () {
    $project = Project::factory()->create();
    $user = reportExtrasMember($project);
    $field = CustomField::factory()->create(['customized_type' => 'time_entry', 'field_format' => CustomFieldFormat::Bool->value, 'name' => 'Overtime']);
    reportExtrasEntry($project, $user, 1)->setCustomFieldValues([$field->id => true]);
    reportExtrasEntry($project, $user, 2)->setCustomFieldValues([$field->id => false]);
    reportExtrasEntry($project, $user, 4);

    $rows = collect(Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->set('criteria', ["cf_time_{$field->id}"])->get('report')->rows)
        ->mapWithKeys(fn ($row) => [$row['labels'][0] => $row['total']]);

    expect($rows->all())->toBe(['(なし)' => 4.0, 'いいえ' => 2.0, 'はい' => 1.0]);
});

test('free-text, multi-value and hidden-role custom fields are not offered as axes', function () {
    $project = Project::factory()->create();
    $user = reportExtrasMember($project);
    $text = CustomField::factory()->create(['customized_type' => 'time_entry', 'name' => 'Free text']);
    $multiple = CustomField::factory()->list(['x', 'y'])->multiple()->create(['customized_type' => 'time_entry', 'name' => 'Many']);
    $hidden = CustomField::factory()->list(['x'])->create(['customized_type' => 'time_entry', 'name' => 'Hidden']);
    $hidden->roles()->attach(Role::factory()->create());

    $axes = Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->get('availableAxes');

    expect($axes)->not->toHaveKey("cf_time_{$text->id}")->not->toHaveKey("cf_time_{$multiple->id}")->not->toHaveKey("cf_time_{$hidden->id}")->not->toHaveKey('project');
});

test('the report exports a CSV with headings, rows and a totals row', function () {
    $project = Project::factory()->create();
    $user = reportExtrasMember($project);
    $user->update(['name' => 'Alice']);
    reportExtrasEntry($project, $user, 2.5);

    $component = Livewire::actingAs($user)->test('time-entries.report', ['project' => $project])->set('criteria', ['user'])->set('period', 'year')->call('exportCsv');
    $csv = base64_decode($component->effects['download']['content']);

    expect($csv)->toBe("\xEF\xBB\xBF".csvRow(['担当者', '2026', '合計']).csvRow(['Alice', '2.50', '2.50']).csvRow(['合計', '2.50', '2.50']));
});

test('the cross-project report adds a project axis and only counts visible time', function () {
    $alpha = Project::factory()->create(['name' => 'Alpha']);
    $beta = Project::factory()->create(['name' => 'Beta']);
    $secret = Project::factory()->private()->create(['name' => 'Secret']);
    $user = reportExtrasMember($alpha);
    Member::factory()->for($beta)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_time_entries']]));
    reportExtrasEntry($alpha, $user, 1);
    reportExtrasEntry($beta, $user, 2);
    reportExtrasEntry($secret, User::factory()->create(), 9);

    $page = Livewire::actingAs($user)->test('time-entries.report')->set('criteria', ['project']);
    $rows = collect($page->get('report')->rows)->mapWithKeys(fn ($row) => [$row['labels'][0] => $row['total']]);

    expect($page->get('availableAxes'))->toHaveKey('project')
        ->and($rows->all())->toBe(['Alpha' => 1.0, 'Beta' => 2.0]);
});

test('the cross-project report respects own-only visibility per project', function () {
    $alpha = Project::factory()->create();
    $user = reportExtrasMember($alpha, 'own');
    reportExtrasEntry($alpha, $user, 1);
    reportExtrasEntry($alpha, User::factory()->create(), 5);

    $page = Livewire::actingAs($user)->test('time-entries.report')->set('criteria', ['user']);

    expect($page->get('report')->grandTotal)->toBe(1.0);
});

test('the cross-project report opens over HTTP', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('time-entries.global-report'))->assertOk();
});
