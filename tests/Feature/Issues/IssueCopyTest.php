<?php

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

function copyProjectMember(Project $project, array $permissions = ['view_issues', 'add_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('opening the create form with copy_from prefills fields from the source issue', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $user = copyProjectMember($project);

    $source = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'Original subject',
        'description' => 'Original description',
    ]);

    $component = Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($user)
        ->test('issues.form', ['project' => $project]);

    expect($component->get('subject'))->toBe('Original subject')
        ->and($component->get('description'))->toBe('Original description')
        ->and($component->get('tracker_id'))->toBe($tracker->id)
        ->and($component->get('priority_id'))->toBe($priority->id);
});

test('saving a copy creates an independent issue rather than modifying the source', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create();
    $user = copyProjectMember($project);

    $source = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'priority_id' => $priority->id,
        'subject' => 'Original subject',
    ]);

    Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->call('save')
        ->assertRedirect();

    expect(Issue::where('subject', 'Original subject')->count())->toBe(2);
});

test('a copy_from pointing to another project is ignored', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $user = copyProjectMember($project);

    $foreignIssue = Issue::factory()->for($otherProject)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Foreign subject',
    ]);

    $component = Livewire::withQueryParams(['copy_from' => $foreignIssue->id])
        ->actingAs($user)
        ->test('issues.form', ['project' => $project]);

    expect($component->get('subject'))->toBe('');
});

test('copying an issue also prefills its custom field values', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);
    $user = copyProjectMember($project);

    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);

    $source = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $source->setCustomFieldValues([$field->id => 'copied value']);

    $component = Livewire::withQueryParams(['copy_from' => $source->id])
        ->actingAs($user)
        ->test('issues.form', ['project' => $project]);

    expect($component->get('customFieldValues')[$field->id])->toBe('copied value');
});
