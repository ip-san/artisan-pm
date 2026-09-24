<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{project: Project, user: User}
 */
function bulkCfSetup(): array
{
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]));

    return compact('project', 'user');
}

function bulkCfIssue(Project $project, Tracker $tracker): Issue
{
    $project->trackers()->syncWithoutDetaching([$tracker->id]);

    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

function bulkCfField(Tracker $tracker, array $attributes = []): CustomField
{
    $field = CustomField::factory()->create($attributes);
    $field->trackers()->attach($tracker);

    return $field;
}

test('bulk edit sets a shared custom field on every selected issue and journals it', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $field = bulkCfField($tracker, ['name' => 'Team']);
    $a = bulkCfIssue($project, $tracker);
    $b = bulkCfIssue($project, $tracker);
    $a->setCustomFieldValues([$field->id => 'old']);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $a->id, (string) $b->id])->assertSee('data-bulk-custom-fields', false)
        ->set("bulkCustomFieldValues.{$field->id}", 'Platform')->call('applyBulkEdit')->assertHasNoErrors();

    expect($a->fresh()->customValue($field))->toBe('Platform')->and($b->fresh()->customValue($field))->toBe('Platform')
        ->and($a->journals()->count())->toBe(1);
});

test('only fields every selected issue has are offered', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $bugs = Tracker::factory()->create();
    $features = Tracker::factory()->create();
    $shared = bulkCfField($bugs, ['name' => 'Shared']);
    $shared->trackers()->attach($features);
    $bugOnly = bulkCfField($bugs, ['name' => 'Bug only']);
    $a = bulkCfIssue($project, $bugs);
    $b = bulkCfIssue($project, $features);

    $page = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $a->id, (string) $b->id]);

    expect($page->get('bulkCustomFields')->pluck('id')->all())->toBe([$shared->id])->and($page->get('bulkCustomFields')->pluck('id')->all())->not->toContain($bugOnly->id);
});

test('blank values change nothing and a bad value blocks the whole edit', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $text = bulkCfField($tracker);
    $number = bulkCfField($tracker, ['field_format' => CustomFieldFormat::Int->value]);
    $issue = bulkCfIssue($project, $tracker);
    $issue->setCustomFieldValues([$text->id => 'keep']);

    $page = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id]);
    $page->set("bulkCustomFieldValues.{$text->id}", '')->set('bulkDoneRatio', 30)->call('applyBulkEdit')->assertHasNoErrors();
    expect($issue->fresh()->customValue($text))->toBe('keep')->and($issue->fresh()->done_ratio)->toBe(30);

    $page->set('selected', [(string) $issue->id])->set("bulkCustomFieldValues.{$number->id}", 'abc')->set('bulkDoneRatio', 60)->call('applyBulkEdit')->assertHasErrors(["bulkCustomFieldValues.{$number->id}"]);
    expect($issue->fresh()->done_ratio)->toBe(30);
});

test('read-only and multi-value fields are not offered and a forced value is ignored', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $locked = bulkCfField($tracker, ['editable' => false]);
    $many = bulkCfField($tracker, ['multiple' => true, 'field_format' => CustomFieldFormat::List->value, 'possible_values' => ['a', 'b']]);
    $issue = bulkCfIssue($project, $tracker);

    $page = Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id]);
    expect($page->get('bulkCustomFields')->pluck('id')->all())->not->toContain($locked->id, $many->id);

    $page->set("bulkCustomFieldValues.{$locked->id}", 'forced')->set('bulkDoneRatio', 10)->call('applyBulkEdit');
    expect($issue->fresh()->customValue($locked))->toBeNull();
});

test('a role that cannot see a field never gets it in the bulk form', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $hidden = bulkCfField($tracker, ['name' => 'Managers only']);
    $hidden->roles()->attach(Role::factory()->create());
    $issue = bulkCfIssue($project, $tracker);

    expect(Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id])->get('bulkCustomFields')->pluck('id')->all())->not->toContain($hidden->id);
});

test('the context menu offers list and boolean fields as submenus and sets or clears them', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $list = bulkCfField($tracker, ['name' => 'Stage', 'field_format' => CustomFieldFormat::List->value, 'possible_values' => ['Alpha', 'Beta']]);
    $flag = bulkCfField($tracker, ['name' => 'Urgent', 'field_format' => CustomFieldFormat::Bool->value]);
    $text = bulkCfField($tracker, ['name' => 'Free text']);
    $a = bulkCfIssue($project, $tracker);
    $b = bulkCfIssue($project, $tracker);
    $a->setCustomFieldValues([$list->id => 'Alpha']);

    $page = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->assertSeeHtml('data-context-menu-custom-field="'.$list->id.'"')
        ->assertSeeHtml('data-context-menu-custom-field="'.$flag->id.'"')
        ->assertDontSeeHtml('data-context-menu-custom-field="'.$text->id.'"');

    $page->call('contextUpdateCustomField', $list->id, 'Beta')->assertHasNoErrors();
    expect($a->fresh()->customValue($list))->toBe('Beta')->and($b->fresh()->customValue($list))->toBe('Beta');

    $page->set('selected', [(string) $a->id])->call('contextUpdateCustomField', $flag->id, '1');
    expect($a->fresh()->customValue($flag))->toBeTrue();

    $page->set('selected', [(string) $a->id])->call('contextUpdateCustomField', $list->id, '__none__');
    expect($a->fresh()->customValue($list))->toBeNull()->and($b->fresh()->customValue($list))->toBe('Beta');
});

test('the context menu refuses values outside the list, clearing a required field and hidden fields', function () {
    ['project' => $project, 'user' => $user] = bulkCfSetup();
    $tracker = Tracker::factory()->create();
    $required = bulkCfField($tracker, ['field_format' => CustomFieldFormat::List->value, 'possible_values' => ['Alpha', 'Beta'], 'is_required' => true]);
    $hidden = bulkCfField($tracker, ['field_format' => CustomFieldFormat::List->value, 'possible_values' => ['Alpha']]);
    $hidden->roles()->attach(Role::factory()->create());
    $issue = bulkCfIssue($project, $tracker);
    $issue->setCustomFieldValues([$required->id => 'Alpha']);

    $page = fn () => Livewire::actingAs($user)->test('issues.index', ['project' => $project])->set('selected', [(string) $issue->id]);

    $page()->assertDontSeeHtml('data-context-menu-custom-field="'.$hidden->id.'"')->call('contextUpdateCustomField', $hidden->id, 'Alpha')->assertForbidden();
    $page()->call('contextUpdateCustomField', $required->id, 'Gamma')->assertStatus(422);
    $page()->call('contextUpdateCustomField', $required->id, '__none__')->assertStatus(422);

    expect($issue->fresh()->customValue($required))->toBe('Alpha')->and($issue->fresh()->customValue($hidden))->toBeNull();
});

test('a member without edit_issues cannot use the custom field menu', function () {
    $project = Project::factory()->create();
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    $tracker = Tracker::factory()->create();
    $list = bulkCfField($tracker, ['field_format' => CustomFieldFormat::List->value, 'possible_values' => ['Alpha']]);
    $issue = bulkCfIssue($project, $tracker);

    Livewire::actingAs($viewer)->test('issues.index', ['project' => $project])
        ->set('selected', [(string) $issue->id])
        ->call('contextUpdateCustomField', $list->id, 'Alpha')
        ->assertForbidden();

    expect($issue->fresh()->customValue($list))->toBeNull();
});
