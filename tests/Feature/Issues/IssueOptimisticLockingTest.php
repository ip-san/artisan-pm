<?php

use App\Exceptions\StaleIssueUpdateException;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Livewire\Livewire;

function lockingProjectMember(Project $project, array $permissions = ['view_issues', 'edit_issues']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

test('lock_version increments on every save', function () {
    $issue = Issue::factory()->create();
    $actor = User::factory()->create();

    expect($issue->lock_version)->toBe(0);

    $updated = app(IssueService::class)->update($issue, ['subject' => 'Changed once'], $actor);
    expect($updated->lock_version)->toBe(1);

    $updated = app(IssueService::class)->update($updated, ['subject' => 'Changed twice'], $actor);
    expect($updated->lock_version)->toBe(2);
});

test('updating with a stale lock_version throws instead of silently overwriting', function () {
    $issue = Issue::factory()->create(['subject' => 'Original']);
    $actor = User::factory()->create();

    // Someone else saves first, bumping lock_version to 1.
    app(IssueService::class)->update($issue->fresh(), ['subject' => 'Someone else changed it'], $actor);

    // This editor's form still holds the lock_version (0) captured when it
    // loaded, but $issue here is freshly fetched (as it would be via route
    // or Livewire model binding), so its lock_version is already 1 —
    // exactly the mismatch a real second submit would produce.
    expect(fn () => app(IssueService::class)->update($issue->fresh(), ['subject' => 'My change'], $actor, expectedLockVersion: 0))
        ->toThrow(StaleIssueUpdateException::class);

    expect($issue->fresh()->subject)->toBe('Someone else changed it');
});

test('updating with the current lock_version succeeds', function () {
    $issue = Issue::factory()->create(['subject' => 'Original']);
    $actor = User::factory()->create();

    $updated = app(IssueService::class)->update($issue, ['subject' => 'My change'], $actor, expectedLockVersion: 0);

    expect($updated->subject)->toBe('My change')
        ->and($updated->lock_version)->toBe(1);
});

test('the issue edit form surfaces a conflict error instead of overwriting a concurrent change', function () {
    $project = Project::factory()->create();
    $user = lockingProjectMember($project);
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Original subject',
    ]);
    $project->trackers()->attach($issue->tracker_id);

    $component = Livewire::actingAs($user)->test('issues.form', ['project' => $project, 'issue' => $issue]);

    // Someone else updates the issue after this editor loaded the form.
    app(IssueService::class)->update($issue->fresh(), ['subject' => 'Changed by someone else'], $user);

    $component->set('subject', 'My conflicting edit')->call('save');

    $component->assertHasErrors('lockVersion');
    expect($issue->fresh()->subject)->toBe('Changed by someone else');
});
