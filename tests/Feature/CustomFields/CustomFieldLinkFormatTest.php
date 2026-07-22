<?php

use App\CustomFields\Formats\LinkFormat;
use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function linkFormatMember(Project $project, array $permissions = ['view_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('setting and reading a link custom field value round-trips correctly', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Link->value]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'https://example.com/docs']);

    expect($issue->customValue($field))->toBe('https://example.com/docs');
});

test('a link custom field accepts a value with no scheme, matching Redmine\'s lack of URL validation', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Link->value]);
    $field->trackers()->attach($tracker);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'example.com/docs']);

    expect($issue->customValue($field))->toBe('example.com/docs');
});

test('LinkFormat::href prefixes http:// only when the value has no scheme', function () {
    expect(LinkFormat::href('example.com'))->toBe('http://example.com')
        ->and(LinkFormat::href('https://example.com'))->toBe('https://example.com')
        ->and(LinkFormat::href('ftp://example.com/file'))->toBe('ftp://example.com/file');
});

test('a link custom field value renders as a clickable link on the issue show page', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Link->value, 'name' => 'Docs']);
    $field->trackers()->attach($tracker);

    $user = linkFormatMember($project);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'example.com/docs']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSeeHtml('href="http://example.com/docs"');
});
