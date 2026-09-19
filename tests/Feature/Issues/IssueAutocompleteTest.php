<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Issues\IssueSuggestions;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $attributes
 */
function autocompleteIssue(Project $project, string $subject, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => $subject,
        ...$attributes,
    ]);
}

function autocompleteMember(Project $project, array $permissions = ['view_issues', 'add_issues', 'edit_issues', 'manage_subtasks', 'manage_issue_relations'], string $visibility = 'all'): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, 'issues_visibility' => $visibility]));

    return $user;
}

test('suggestions match a subject fragment case-insensitively and an id with or without #', function () {
    $project = Project::factory()->create();
    $user = autocompleteMember($project);
    $login = autocompleteIssue($project, 'Login page is broken');
    autocompleteIssue($project, 'Dark mode');

    expect(IssueSuggestions::search($user, 'LOGIN', $project)->pluck('id')->all())->toBe([$login->id])
        ->and(IssueSuggestions::search($user, "#{$login->id}", $project)->pluck('id')->all())->toBe([$login->id])
        ->and(IssueSuggestions::search($user, (string) $login->id, $project)->pluck('id')->all())->toContain($login->id)
        ->and(IssueSuggestions::search($user, '', $project))->toHaveCount(0)
        ->and(IssueSuggestions::search(null, 'login', $project))->toHaveCount(0);
});

test('percent and underscore in the search are literal', function () {
    $project = Project::factory()->create();
    $user = autocompleteMember($project);
    autocompleteIssue($project, '100% done');
    autocompleteIssue($project, 'Plain subject');

    expect(IssueSuggestions::search($user, '%', $project))->toHaveCount(1);
});

test('private issues and other projects stay out of the suggestions', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $user = autocompleteMember($project, ['view_issues'], 'default');
    autocompleteIssue($project, 'Secret plan', ['is_private' => true, 'author_id' => User::factory()->create()->id]);
    autocompleteIssue($other, 'Secret elsewhere');
    autocompleteIssue($project, 'Secret but public');

    expect(IssueSuggestions::search($user, 'secret', $project)->pluck('subject')->all())->toBe(['Secret but public']);
});

test('relations can look across projects only when the setting allows it and the viewer may see them', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $hidden = Project::factory()->private()->create();
    $user = autocompleteMember($project);
    Member::factory()->for($other)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    autocompleteIssue($other, 'Shared topic elsewhere');
    autocompleteIssue($hidden, 'Shared topic hidden');

    expect(IssueSuggestions::search($user, 'shared topic', $project, allowOtherProjects: true))->toHaveCount(0);

    Setting::set('cross_project_issue_relations', true);
    expect(IssueSuggestions::search($user, 'shared topic', $project, allowOtherProjects: true)->pluck('subject')->all())->toBe(['Shared topic elsewhere'])
        ->and(IssueSuggestions::search($user, 'shared topic', $project)->pluck('subject')->all())->toBe([]);
});

test('the issue itself is never suggested', function () {
    $project = Project::factory()->create();
    $user = autocompleteMember($project);
    $issue = autocompleteIssue($project, 'Only me');

    expect(IssueSuggestions::search($user, 'only', $project, excludeIssueId: $issue->id))->toHaveCount(0);
});

test('the issue form offers parent suggestions and picking one fills the parent', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $user = autocompleteMember($project);
    $parent = autocompleteIssue($project, 'Epic feature');

    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project])->assertSee('data-parent-search', false)->set('parentSearch', 'epic');
    expect($form->get('parentSuggestions')->pluck('id')->all())->toBe([$parent->id]);

    $form->call('pickParent', $parent->id)->assertSet('parent_id', $parent->id)->assertSet('parentSearch', '');
});

test('the parent picker on an existing issue never offers the issue itself', function () {
    $project = Project::factory()->create();
    $project->trackers()->attach(Tracker::factory()->create());
    $user = autocompleteMember($project);
    $issue = autocompleteIssue($project, 'Epic feature');

    $form = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue])->set('parentSearch', 'epic');

    expect($form->get('parentSuggestions'))->toHaveCount(0);
});

test('the issue page offers related suggestions and picking one fills the field', function () {
    $project = Project::factory()->create();
    $user = autocompleteMember($project);
    $issue = autocompleteIssue($project, 'Current');
    $target = autocompleteIssue($project, 'Blocked by this one');

    $page = Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $issue])->assertSee('data-related-search', false)->set('relatedSearch', 'blocked');
    expect($page->get('relatedSuggestions')->pluck('id')->all())->toBe([$target->id]);

    $page->call('pickRelated', $target->id)->assertSet('relatedIssueId', $target->id)->assertSet('relatedSearch', '');
});
