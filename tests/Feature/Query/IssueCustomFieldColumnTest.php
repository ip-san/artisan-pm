<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function cfColumnMember(Project $project): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues']]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('project-applicable custom fields are offered as columns; foreign-scoped ones are not', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = cfColumnMember($project);
    $tracker = Tracker::factory()->create();

    $globalField = CustomField::factory()->create(['name' => 'Client email']);
    $globalField->trackers()->attach($tracker);

    $foreignField = CustomField::factory()->create(['name' => 'Other project only']);
    $foreignField->trackers()->attach($tracker);
    $foreignField->projects()->attach($otherProject);

    $columns = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->get('availableColumns');

    expect($columns)->toHaveKey("cf_{$globalField->id}")
        ->and($columns["cf_{$globalField->id}"])->toBe('Client email')
        ->and($columns)->not->toHaveKey("cf_{$foreignField->id}");
});

test('a selected custom field column renders single and multiple values', function () {
    $project = Project::factory()->create();
    $user = cfColumnMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $single = CustomField::factory()->create(['name' => 'Contact']);
    $single->trackers()->attach($tracker);
    $multi = CustomField::factory()->multiple()->create(['name' => 'Tags', 'field_format' => CustomFieldFormat::String->value]);
    $multi->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([
        $single->id => 'client@example.com',
        $multi->id => ['red', 'blue'],
    ]);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', "cf_{$single->id}", "cf_{$multi->id}"]);

    $loaded = $component->get('issues')->getCollection()->firstWhere('id', $issue->id);

    expect($component->instance()->columnValue($loaded, "cf_{$single->id}"))->toBe('client@example.com')
        ->and($component->instance()->columnValue($loaded, "cf_{$multi->id}"))->toBe('red, blue');
});

test('csv export includes the custom field column header and value', function () {
    $project = Project::factory()->create();
    $user = cfColumnMember($project);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id, 'subject' => 'CSV issue']);
    $issue->setCustomFieldValues([$field->id => 'High']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->set('columns', ['subject', "cf_{$field->id}"])
        ->call('exportCsv')
        ->assertFileDownloaded(
            "{$project->identifier}-issues.csv",
            "\xEF\xBB\xBF".csvRow(['題名', 'Severity']).csvRow(['CSV issue', 'High'])
        );
});
