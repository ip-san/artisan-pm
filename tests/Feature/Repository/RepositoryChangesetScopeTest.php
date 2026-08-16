<?php

use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

function changesetScopeMember(Project $project, array $permissions, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function createChangesetScopeGitRepo(): string
{
    $path = config('scm.repositories_root').'/changeset-scope-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', 'test@example.com']);
    $run(['git', 'config', 'user.name', 'Test Committer']);

    file_put_contents("{$path}/a.txt", 'hello');
    $run(['git', 'add', '-A']);
    $run(['git', 'commit', '-q', '-m', 'Initial commit']);

    return $path;
}

afterEach(function () {
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'changeset-scope-test-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('a changeset reached through an unrelated project in the URL 404s instead of borrowing that project\'s permissions', function () {
    // {changeset} can't be route-scoped under {project} (Project has no
    // changesets relation to scope through), so show.blade.php checks the
    // ownership itself. Without that check the component kept $project from
    // the URL and authorized manageRelatedIssues against it — letting a user
    // who only holds view_changesets on the owning project edit its
    // changeset's related issues by borrowing another project's permission.
    $owner = Project::factory()->create();
    $repository = Repository::factory()->for($owner)->create(['path' => createChangesetScopeGitRepo()]);
    $changeset = Changeset::factory()->for($repository)->create();

    // May view the owning project's changesets, but not manage their links.
    $user = changesetScopeMember($owner, ['view_changesets', 'view_issues']);
    // ...while holding manage_related_issues on an unrelated project.
    $unrelated = Project::factory()->create();
    changesetScopeMember($unrelated, ['manage_related_issues', 'view_changesets', 'view_issues'], $user);

    $issue = Issue::factory()->for($owner)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    // Through the owning project the action is refused, as it should be.
    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $owner, 'changeset' => $changeset])
        ->set('newIssueReference', (string) $issue->id)
        ->call('addRelatedIssue');

    expect($changeset->issues()->count())->toBe(0);

    // Through the unrelated project the page must not load at all.
    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $unrelated, 'changeset' => $changeset])
        ->assertNotFound();

    expect($changeset->issues()->count())->toBe(0);
});
