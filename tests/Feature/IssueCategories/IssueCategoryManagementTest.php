<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function categoryProjectMember(Project $project, array $permissions = ['manage_categories']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('a member with manage_categories can create a category', function () {
    $project = Project::factory()->create();
    $user = categoryProjectMember($project);

    Livewire::actingAs($user)
        ->test('issue-categories.form', ['project' => $project])
        ->set('name', 'Backend')
        ->call('save')
        ->assertRedirect(route('issue-categories.index', $project));

    $category = IssueCategory::where('project_id', $project->id)->where('name', 'Backend')->firstOrFail();

    expect($category->assigned_to_id)->toBeNull();
});

test('a member with manage_categories can set a default assignee', function () {
    $project = Project::factory()->create();
    $user = categoryProjectMember($project);
    $assignee = categoryProjectMember($project, ['view_issues']);

    Livewire::actingAs($user)
        ->test('issue-categories.form', ['project' => $project])
        ->set('name', 'Frontend')
        ->set('assigned_to_id', $assignee->id)
        ->call('save')
        ->assertRedirect(route('issue-categories.index', $project));

    $category = IssueCategory::where('project_id', $project->id)->where('name', 'Frontend')->firstOrFail();

    expect($category->assigned_to_id)->toBe($assignee->id);
});

test('a user without manage_categories cannot access category administration', function () {
    $project = Project::factory()->create();
    $user = categoryProjectMember($project, ['view_issues']);

    Livewire::actingAs($user)->test('issue-categories.index', ['project' => $project])->assertForbidden();
    Livewire::actingAs($user)->test('issue-categories.form', ['project' => $project])->assertForbidden();
});

test('a member with manage_categories can delete an unused category', function () {
    $project = Project::factory()->create();
    $user = categoryProjectMember($project);
    $category = IssueCategory::factory()->for($project)->create();

    Livewire::actingAs($user)->test('issue-categories.index', ['project' => $project])->call('delete', $category->id);

    expect(IssueCategory::find($category->id))->toBeNull();
});

test('a category in use by an issue cannot be deleted', function () {
    $project = Project::factory()->create();
    $user = categoryProjectMember($project);
    $category = IssueCategory::factory()->for($project)->create();
    Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'author_id' => $user->id,
        'category_id' => $category->id,
    ]);

    Livewire::actingAs($user)->test('issue-categories.index', ['project' => $project])->call('delete', $category->id);

    expect(IssueCategory::find($category->id))->not->toBeNull();
});

test('a category belonging to another project is rejected with a 404', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = categoryProjectMember($project);
    $foreignCategory = IssueCategory::factory()->for($otherProject)->create();

    Livewire::actingAs($user)
        ->test('issue-categories.form', ['project' => $project, 'issueCategory' => $foreignCategory])
        ->assertStatus(404);
});

test('the issue form scopes the category selector and assignee validation to the project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $category = IssueCategory::factory()->for($project)->create();
    $foreignCategory = IssueCategory::factory()->for($otherProject)->create();

    $user = categoryProjectMember($project, ['view_issues', 'add_issues']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', $priority->id)
        ->set('subject', 'Fix the login page')
        ->set('category_id', $category->id)
        ->call('save')
        ->assertRedirect();

    $issue = Issue::where('subject', 'Fix the login page')->firstOrFail();
    expect($issue->category_id)->toBe($category->id);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('priority_id', $priority->id)
        ->set('subject', 'Cross-project category should fail')
        ->set('category_id', $foreignCategory->id)
        ->call('save')
        ->assertHasErrors(['category_id']);
});

test('selecting a category prefills its default assignee but does not override an existing choice', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $priority = Enumeration::factory()->create(['is_default' => true]);
    $defaultAssignee = categoryProjectMember($project, ['view_issues']);
    $chosenAssignee = categoryProjectMember($project, ['view_issues']);
    $category = IssueCategory::factory()->for($project)->create(['assigned_to_id' => $defaultAssignee->id]);

    $user = categoryProjectMember($project, ['view_issues', 'add_issues']);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('category_id', $category->id)
        ->assertSet('assigned_to_id', $defaultAssignee->id);

    Livewire::actingAs($user)
        ->test('issues.form', ['project' => $project])
        ->set('tracker_id', $tracker->id)
        ->set('assigned_to_id', $chosenAssignee->id)
        ->set('category_id', $category->id)
        ->assertSet('assigned_to_id', $chosenAssignee->id);
});

test('the issue list offers category as a display column', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $category = IssueCategory::factory()->for($project)->create(['name' => 'Backend']);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'category_id' => $category->id,
    ]);

    $user = categoryProjectMember($project, ['view_issues']);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('columns', ['subject', 'category_id']);

    expect($component->instance()->columnValue($issue, 'category_id'))->toBe('Backend');
});
