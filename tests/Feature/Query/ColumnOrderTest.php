<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;

function columnOrderMember(Project $project): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'view_time_entries', 'log_time']])
    );

    return $user;
}

test('a selected issue list column can be moved earlier and later', function () {
    $project = Project::factory()->create();
    $user = columnOrderMember($project);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'status_id', 'due_date'])
        ->call('moveColumn', 'due_date', -1)
        ->assertSet('columns', ['subject', 'due_date', 'status_id'])
        ->call('moveColumn', 'subject', 1)
        ->assertSet('columns', ['due_date', 'subject', 'status_id'])
        ->call('moveColumn', 'subject', 1)
        ->assertSet('columns', ['due_date', 'status_id', 'subject']);
});

test('moving past either end and unknown keys change nothing', function () {
    $project = Project::factory()->create();
    $user = columnOrderMember($project);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'status_id'])
        ->call('moveColumn', 'subject', -1)
        ->assertSet('columns', ['subject', 'status_id'])
        ->call('moveColumn', 'status_id', 1)
        ->assertSet('columns', ['subject', 'status_id'])
        ->call('moveColumn', 'password', 1)
        ->call('moveColumn', 'due_date', -1)
        ->assertSet('columns', ['subject', 'status_id']);
});

test('the table headers, CSV and a saved query follow the chosen order', function () {
    $project = Project::factory()->create();
    $user = columnOrderMember($project);
    App\Models\Issue::factory()->for($project)->create([
        'tracker_id' => App\Models\Tracker::factory()->create()->id,
        'status_id' => App\Models\IssueStatus::factory()->create()->id,
        'priority_id' => App\Models\Enumeration::factory()->create()->id,
        'subject' => 'Order me',
    ]);

    $component = Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'status_id', 'due_date'])
        ->call('moveColumn', 'due_date', -1)
        ->call('moveColumn', 'due_date', -1)
        ->set('statusFilter', 'all');

    preg_match_all('/column-heading-([a-z_]+)"/', $component->html(), $headings);
    expect($headings[1])->toBe(['due_date', 'subject', 'status_id']);

    $component->call('exportCsv')->assertFileDownloaded("{$project->identifier}-issues.csv");

    $component->set('newQueryName', 'Ordered')->call('saveQuery');
    expect(App\Models\Query::query()->where('name', 'Ordered')->firstOrFail()->column_names)->toBe(['due_date', 'subject', 'status_id']);
});

test('the reorder control appears only with two or more selected columns', function () {
    $project = Project::factory()->create();
    $user = columnOrderMember($project);

    Livewire::actingAs($user)->test('issues.index', ['project' => $project])
        ->set('columns', ['subject'])
        ->assertDontSeeHtml('data-column-order')
        ->set('columns', ['subject', 'status_id'])
        ->assertSeeHtml('data-column-order');
});

test('time entry list columns can be reordered and saved the same way', function () {
    $project = Project::factory()->create();
    $user = columnOrderMember($project);

    $component = Livewire::actingAs($user)->test('time-entries.index', ['project' => $project])
        ->set('columns', ['spent_on', 'hours', 'comments'])
        ->call('moveColumn', 'comments', -5)
        ->assertSet('columns', ['spent_on', 'comments', 'hours'])
        ->call('moveColumn', 'comments', -1)
        ->assertSet('columns', ['comments', 'spent_on', 'hours']);

    $component->set('newQueryName', 'Time ordered')->call('saveQuery');

    expect(App\Models\Query::query()->where('name', 'Time ordered')->firstOrFail()->column_names)->toBe(['comments', 'spent_on', 'hours']);
});
