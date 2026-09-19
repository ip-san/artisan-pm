<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Issues\DoneRatioSteps;
use Livewire\Livewire;

function doneRatioEditor(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues']])
    );

    return $user;
}

test('the interval defaults to 10 and offers 0 to 100 in that step', function () {
    expect(DoneRatioSteps::interval())->toBe(10)
        ->and(DoneRatioSteps::options())->toBe([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
});

test('an interval of 5 doubles the choices and an invalid stored value falls back to 10', function () {
    Setting::set('issue_done_ratio_interval', 5);
    expect(DoneRatioSteps::options())->toHaveCount(21)->and(DoneRatioSteps::options()[1])->toBe(5);

    Setting::set('issue_done_ratio_interval', 7);
    expect(DoneRatioSteps::interval())->toBe(10);
});

test('a current value that is not a step is kept in place, and out of range values are not', function () {
    expect(DoneRatioSteps::options(10, 35))->toContain(35)
        ->and(array_search(35, DoneRatioSteps::options(10, 35), true))->toBe(4)
        ->and(DoneRatioSteps::options(10, 250))->not->toContain(250)
        ->and(DoneRatioSteps::options(10, null))->toHaveCount(11);
});

test('an explicit interval wins over the site setting', function () {
    Setting::set('issue_done_ratio_interval', 10);

    expect(DoneRatioSteps::options(5))->toHaveCount(21);
});

test('the issue form offers progress in the configured step and keeps an unaligned value', function () {
    Setting::set('issue_done_ratio_interval', 5);
    $project = Project::factory()->create();
    $user = doneRatioEditor($project);
    $issue = Issue::factory()->for($project)->create(['done_ratio' => 35, 'tracker_id' => Tracker::factory()->create()->id]);
    $project->trackers()->attach($issue->tracker_id);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertSeeHtml('<option value="15">15 %</option>')
        ->assertSeeHtml('<option value="35">35 %</option>');

    Setting::set('issue_done_ratio_interval', 10);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->assertDontSeeHtml('<option value="15">15 %</option>')
        ->assertSeeHtml('<option value="35">35 %</option>');
});

test('the bulk edit progress select follows the interval', function () {
    Setting::set('issue_done_ratio_interval', 5);
    $project = Project::factory()->create();
    $user = doneRatioEditor($project);
    $issue = Issue::factory()->for($project)->create();

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [$issue->id])
        ->assertSeeHtml('<option value="5">5%</option>');
});

test('the settings page saves the interval and rejects other values', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('issue_done_ratio_interval', 10)
        ->set('issue_done_ratio_interval', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('issue_done_ratio_interval'))->toBe(5);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('issue_done_ratio_interval', 7)
        ->call('save')
        ->assertHasErrors('issue_done_ratio_interval');
});

test('the status form offers the default progress as a select', function () {
    $admin = User::factory()->admin()->create();
    $status = IssueStatus::factory()->create(['default_done_ratio' => 40]);

    Livewire::actingAs($admin)->test('issue-statuses.form', ['issueStatus' => $status])
        ->assertSet('default_done_ratio', 40)
        ->assertSeeHtml('<option value="40">40 %</option>');
});

test('a progressbar custom field saves its interval, defaulting to the site setting', function () {
    Setting::set('issue_done_ratio_interval', 5);
    $admin = User::factory()->admin()->create();

    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Completion')
        ->set('customized_type', 'issue')
        ->set('trackerIds', [$tracker->id])
        ->set('field_format', CustomFieldFormat::Progressbar->value)
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::query()->where('name', 'Completion')->firstOrFail();
    expect($field->ratio_interval)->toBe(5);

    Livewire::actingAs($admin)->test('custom-fields.form', ['customField' => $field])
        ->set('ratio_interval', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect($field->fresh()->ratio_interval)->toBe(10);

    Livewire::actingAs($admin)->test('custom-fields.form', ['customField' => $field])
        ->set('ratio_interval', 15)
        ->call('save')
        ->assertHasErrors('ratio_interval');
});

test('a non-progressbar field never stores an interval', function () {
    $admin = User::factory()->admin()->create();

    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Plain')
        ->set('customized_type', 'issue')
        ->set('trackerIds', [$tracker->id])
        ->set('field_format', CustomFieldFormat::String->value)
        ->set('ratio_interval', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(CustomField::query()->where('name', 'Plain')->firstOrFail()->ratio_interval)->toBeNull();
});

test('a progressbar custom field input renders a select in its own steps', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $project->trackers()->attach($tracker);
    $user = doneRatioEditor($project);
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Progressbar->value, 'ratio_interval' => 5]);
    $field->trackers()->attach($tracker);

    Livewire::actingAs($user)->test('issues.form', ['project' => $project])
        ->assertSeeHtml('<option value="5">5 %</option>')
        ->assertSeeHtml('<option value="100">100 %</option>');
});
