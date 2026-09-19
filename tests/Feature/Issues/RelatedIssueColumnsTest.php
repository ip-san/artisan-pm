<?php

use App\Enums\IssueRelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\Issues\RelatedIssueColumns;
use Livewire\Livewire;

function relatedColumnsViewer(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues']])
    );

    return $user;
}

function relatedColumnsFixture(): array
{
    $project = Project::factory()->create();
    $user = relatedColumnsViewer($project);
    $assignee = User::factory()->create(['name' => 'Assigned Person']);
    $parent = Issue::factory()->for($project)->create();
    $child = Issue::factory()->for($project)->create([
        'parent_id' => $parent->id, 'subject' => 'Child task', 'assigned_to_id' => $assignee->id, 'due_date' => '2026-12-24', 'done_ratio' => 40,
    ]);
    $other = Issue::factory()->for($project)->create(['subject' => 'Related issue', 'assigned_to_id' => $assignee->id]);
    IssueRelation::create(['issue_from_id' => $parent->id, 'issue_to_id' => $other->id, 'relation_type' => IssueRelationType::Relates]);

    return [$project, $user, $parent];
}

test('the default columns are status, assignee, dates and progress', function () {
    expect(array_keys(RelatedIssueColumns::selected()))->toBe(RelatedIssueColumns::DEFAULT);
});

test('subtasks and related issues show the default columns without table headers', function () {
    [$project, $user, $parent] = relatedColumnsFixture();

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSee('Child task')
        ->assertSee('Assigned Person')
        ->assertSee('2026-12-24')
        ->assertSee('40%')
        ->assertSee('Related issue')
        ->assertDontSeeHtml('<thead');
});

test('the configured columns and the header row are applied to both tables', function () {
    Setting::set('related_issues_default_columns', ['author_id', 'done_ratio']);
    Setting::set('display_related_issues_table_headers', true);
    [$project, $user, $parent] = relatedColumnsFixture();

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSeeHtml('<thead')
        ->assertSee('作成者')
        ->assertSee('進捗率')
        ->assertDontSee('Assigned Person')
        ->assertDontSee('2026-12-24');
});

test('an empty column selection leaves just the subject', function () {
    Setting::set('related_issues_default_columns', []);
    [$project, $user, $parent] = relatedColumnsFixture();

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->assertSee('Child task')
        ->assertDontSee('Assigned Person')
        ->assertDontSee('40%');
});

test('unknown or no-longer-selectable stored columns are ignored', function () {
    Setting::set('related_issues_default_columns', ['subject', 'tracker_id', 'bogus', 'done_ratio']);

    expect(array_keys(RelatedIssueColumns::selected()))->toBe(['done_ratio']);
});

test('the settings page saves the columns and rejects unknown ones', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->assertSet('related_issues_default_columns', RelatedIssueColumns::DEFAULT)
        ->set('related_issues_default_columns', ['due_date', 'created_at'])
        ->set('display_related_issues_table_headers', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('related_issues_default_columns'))->toBe(['due_date', 'created_at'])
        ->and(Setting::get('display_related_issues_table_headers'))->toBeTrue();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('related_issues_default_columns', ['subject'])
        ->call('save')
        ->assertHasErrors('related_issues_default_columns.0');
});

test('rendering the tables does not lazy load per row', function () {
    Setting::set('related_issues_default_columns', array_keys(RelatedIssueColumns::AVAILABLE));
    [$project, $user, $parent] = relatedColumnsFixture();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])->assertOk();

    $baseline = $queries;
    Issue::factory()->for($project)->count(5)->create(['parent_id' => $parent->id]);
    $queries = 0;

    Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $parent])->assertOk();

    expect($queries)->toBeLessThanOrEqual($baseline + 2);
});
