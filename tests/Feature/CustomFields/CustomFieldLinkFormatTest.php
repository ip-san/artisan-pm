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
        ->and(LinkFormat::href('ftp://example.com/file'))->toBe('ftp://example.com/file')
        ->and(LinkFormat::href('mailto:someone@example.com'))->toBe('mailto:someone@example.com');
});

test('LinkFormat::href neutralizes dangerous URI schemes by prefixing them as http:// instead of passing them through', function () {
    expect(LinkFormat::href('javascript://alert(1)'))->toBe('http://javascript://alert(1)')
        ->and(LinkFormat::href('javascript:alert(1)'))->toBe('http://javascript:alert(1)')
        ->and(LinkFormat::href('data:text/html,<script>alert(1)</script>'))->toBe('http://data:text/html,<script>alert(1)</script>')
        ->and(LinkFormat::href('vbscript:msgbox(1)'))->toBe('http://vbscript:msgbox(1)');

    // Every neutralized value starts with a safe http:// scheme and
    // therefore can never itself be sniffed as javascript/data/vbscript
    // by a browser resolving the href.
    foreach (['javascript://alert(1)', 'javascript:alert(1)', 'data:text/html,x', 'vbscript:msgbox(1)'] as $dangerous) {
        expect(LinkFormat::href($dangerous))->toStartWith('http://');
    }
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

test('a link custom field value with a javascript: scheme never renders as an executable href on the issue show page', function () {
    $tracker = Tracker::factory()->create();
    $project = Project::factory()->create();
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Link->value, 'name' => 'Docs']);
    $field->trackers()->attach($tracker);

    $user = linkFormatMember($project);
    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'javascript://alert(document.cookie)']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertDontSeeHtml('href="javascript:')
        ->assertSeeHtml('href="http://javascript://alert(document.cookie)"');
});
