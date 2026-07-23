<?php

use App\Enums\EnumerationType;
use App\Jobs\AutofetchRepositoryChangesetsJob;
use App\Jobs\RepositorySyncJob;
use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\RepositorySyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function repositoryMember(Project $project, array $permissions = ['view_changesets']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestGitRepo(array $commitMessages): string
{
    return createTestGitRepoWithCommitter('Test Committer', 'test@example.com', $commitMessages);
}

/**
 * @param  array<int, string>  $commitMessages
 */
function createTestGitRepoWithCommitter(string $committerName, string $committerEmail, array $commitMessages): string
{
    $path = sys_get_temp_dir().'/scm-test-'.uniqid();
    mkdir($path);

    $run = fn (array $command) => Process::path($path)->timeout(10)->run($command)->throw();

    $run(['git', 'init', '-q']);
    $run(['git', 'config', 'user.email', $committerEmail]);
    $run(['git', 'config', 'user.name', $committerName]);

    foreach ($commitMessages as $i => $message) {
        file_put_contents("{$path}/file{$i}.txt", "content {$i}\n");
        $run(['git', 'add', '-A']);
        $run(['git', 'commit', '-q', '-m', $message]);
    }

    return $path;
}

afterEach(function () {
    Process::path(sys_get_temp_dir())->run(['find', '.', '-maxdepth', '1', '-name', 'scm-test-*', '-exec', 'rm', '-rf', '{}', ';']);
    Process::path(config('scm.repositories_root'))->run(['find', '.', '-maxdepth', '1', '-name', 'allowed-*', '-exec', 'rm', '-rf', '{}', ';']);
});

test('syncing a repository records a changeset per commit, oldest first', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Initial commit', 'Second commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    $created = app(RepositorySyncService::class)->sync($repository);

    expect($created)->toBe(2)
        ->and($repository->changesets()->count())->toBe(2);

    // Queried directly by id (creation order) rather than through the
    // changesets() relation, which orders by committed_on desc — two
    // commits made moments apart in the test can land within the same
    // git-timestamp second, so committed_on alone isn't a reliable
    // ordering signal here.
    $revisions = Changeset::query()->where('repository_id', $repository->id)->orderBy('id')->pluck('comments')->all();
    expect($revisions)->toBe(['Initial commit', 'Second commit']);
});

test('syncing again only fetches commits after the last synced revision', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['First']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);
    expect($repository->changesets()->count())->toBe(1);

    Process::path($path)->run(['git', 'commit', '--allow-empty', '-q', '-m', 'Second'])->throw();

    $created = app(RepositorySyncService::class)->sync($repository->fresh());

    expect($created)->toBe(1)
        ->and($repository->changesets()->count())->toBe(2);
});

test('a commit message referencing #123 links the changeset to that issue', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('a "fixes #N" commit closes the issue when the committer matches a real user with edit_issues', function () {
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($committerUser)->create()->roles()->attach($role);
    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($closed->id);
});

test('a "fixes #N" commit does not close the issue when the matched user lacks edit_issues on the project', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    // A real user whose email happens to match the commit's spoofable
    // committer field, but who is not a project member (or has no
    // edit_issues permission) — the transition must not apply, or
    // anyone able to push a commit could force status changes
    // attributed to an unrelated user just by faking their git email.
    User::factory()->create(['email' => 'test@example.com']);
    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId);
});

test('a "fixes #N" commit does not change status when the committer matches no user', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId);
});

test('a "fixes #N" commit closes the issue when the committer matches via an explicit repository mapping', function () {
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $mappedUser = User::factory()->create(['email' => 'jane@example.com']);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($mappedUser)->create()->roles()->attach($role);

    $path = createTestGitRepoWithCommitter('Jane Doe', 'jane@old-corp.com', ["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    $repository->committers()->create(['committer' => 'Jane Doe <jane@old-corp.com>', 'user_id' => $mappedUser->id]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($closed->id);
});

test('a repository mapping still requires the mapped user to hold the relevant permission', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    $mappedUser = User::factory()->create();

    $path = createTestGitRepoWithCommitter('Jane Doe', 'jane@old-corp.com', ["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    $repository->committers()->create(['committer' => 'Jane Doe <jane@old-corp.com>', 'user_id' => $mappedUser->id]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId);
});

test('an explicit repository mapping takes precedence over the automatic email match', function () {
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);

    // A user whose email happens to match the raw committer string via
    // the automatic fallback, and who does have permission...
    $autoMatchUser = User::factory()->create(['email' => 'jane@old-corp.com']);
    Member::factory()->for($project)->for($autoMatchUser)->create()->roles()->attach($role);

    // ...but the explicit mapping points somewhere else, and that mapped
    // user lacks permission — proving the mapping is what actually gets
    // consulted, not the auto-match.
    $mappedUser = User::factory()->create();

    $path = createTestGitRepoWithCommitter('Jane Doe', 'jane@old-corp.com', ["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    $repository->committers()->create(['committer' => 'Jane Doe <jane@old-corp.com>', 'user_id' => $mappedUser->id]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->not->toBe($closed->id);
});

test('a custom commit_fixing_keywords setting is honored instead of the default list', function () {
    $project = Project::factory()->create();
    $closed = IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($committerUser)->create()->roles()->attach($role);
    Setting::set('commit_fixing_keywords', 'resolves, resolve');

    $path = createTestGitRepo(["Resolves #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($closed->id);
});

test('a default keyword no longer matches once commit_fixing_keywords overrides the list', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($committerUser)->create()->roles()->attach($role);
    Setting::set('commit_fixing_keywords', 'resolves, resolve');

    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId);
});

test('an empty commit_fixing_keywords setting disables keyword-based closing entirely', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    $role = Role::factory()->create(['permissions' => ['view_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($committerUser)->create()->roles()->attach($role);
    Setting::set('commit_fixing_keywords', '');

    $path = createTestGitRepo(["Fixes #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId);
});

test('a plain "refs #N" commit links the issue without changing its status', function () {
    $project = Project::factory()->create();
    IssueStatus::factory()->closed()->create();
    $issue = Issue::factory()->for($project)->create();
    $originalStatusId = $issue->status_id;
    User::factory()->create(['email' => 'test@example.com']);
    $path = createTestGitRepo(["Refs #{$issue->id}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect($issue->fresh()->status_id)->toBe($originalStatusId)
        ->and($repository->changesets()->firstOrFail()->issues->pluck('id')->all())->toBe([$issue->id]);
});

test('a "#N @2h" commit message does not log time when commit_logtime_enabled is off', function () {
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committerUser)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'log_time']]));
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);
    $path = createTestGitRepo(["Refs #{$issue->id} @2h"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect(TimeEntry::where('issue_id', $issue->id)->count())->toBe(0);
});

test('a "#N @2h" commit message logs time against that issue when enabled', function () {
    Setting::set('commit_logtime_enabled', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committerUser)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'log_time']]));
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);
    $path = createTestGitRepo(["Refs #{$issue->id} @2h"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $entry = TimeEntry::where('issue_id', $issue->id)->firstOrFail();
    expect((float) $entry->hours)->toBe(2.0)
        ->and($entry->user_id)->toBe($committerUser->id)
        ->and($entry->activity_id)->toBe($activity->id)
        ->and($entry->project_id)->toBe($project->id);
});

test('various @Nh token formats parse to the expected hours', function (string $token, float $expectedHours) {
    Setting::set('commit_logtime_enabled', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committerUser)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'log_time']]));
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);
    $path = createTestGitRepo(["Refs #{$issue->id} @{$token}"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $entry = TimeEntry::where('issue_id', $issue->id)->firstOrFail();
    expect((float) $entry->hours)->toBe($expectedHours);
})->with([
    ['2h', 2.0],
    ['2h30m', 2.5],
    ['30m', 0.5],
    ['1:30', 1.5],
    ['2.5', 2.5],
    ['2,5', 2.5],
]);

test('time is not logged when the committer lacks log_time on the project', function () {
    Setting::set('commit_logtime_enabled', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committerUser)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);
    $path = createTestGitRepo(["Refs #{$issue->id} @2h"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    expect(TimeEntry::where('issue_id', $issue->id)->count())->toBe(0);
});

test('a configured commit_logtime_activity_id is used over the default activity', function () {
    Setting::set('commit_logtime_enabled', true);
    $project = Project::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    $committerUser = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committerUser)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_issues', 'log_time']]));
    Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value, 'is_default' => true]);
    $configuredActivity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    Setting::set('commit_logtime_activity_id', $configuredActivity->id);
    $path = createTestGitRepo(["Refs #{$issue->id} @2h"]);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $entry = TimeEntry::where('issue_id', $issue->id)->firstOrFail();
    expect($entry->activity_id)->toBe($configuredActivity->id);
});

test('changeset files record their action', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->files)->toHaveCount(1)
        ->and($changeset->files->first()->action)->toBe('A')
        ->and($changeset->files->first()->path)->toBe('file0.txt');
});

test('a member with view_changesets can see the repository log', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project);
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);

    Livewire::actingAs($user)->test('repository.index', ['project' => $project])->assertOk();

    $changeset = $repository->changesets()->firstOrFail();
    Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->assertOk()
        ->assertSee($changeset->shortRevision());
});

test('a member without view_changesets is forbidden from the repository', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project, []);

    Livewire::actingAs($user)->test('repository.index', ['project' => $project])->assertForbidden();
});

test('only a member with manage_repository can configure the repository or trigger a sync', function () {
    $project = Project::factory()->create();
    $viewer = repositoryMember($project);
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);
    $allowedPath = config('scm.repositories_root').'/allowed-'.uniqid();
    mkdir($allowedPath);
    Process::path($allowedPath)->run(['git', 'init', '-q'])->throw();

    Livewire::actingAs($viewer)->test('repository.form', ['project' => $project])->assertForbidden();

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', $allowedPath)
        ->call('save');

    $repository = Repository::where('project_id', $project->id)->firstOrFail();
    expect(realpath($repository->path))->toBe(realpath($allowedPath));

    Livewire::actingAs($viewer)
        ->test('repository.index', ['project' => $project])
        ->call('sync')
        ->assertForbidden();
});

test('triggering a sync queues the job rather than running it inline', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    Livewire::actingAs($manager)
        ->test('repository.index', ['project' => $project])
        ->call('sync');

    // With the queue faked, the job never actually runs — proving the
    // "queued" message can't be claiming completion, since nothing here
    // could have created a changeset yet.
    Queue::assertPushed(RepositorySyncJob::class, fn (RepositorySyncJob $job) => true);
    expect($repository->changesets()->count())->toBe(0);
});

test('dispatching a sync for a repository twice in a row only queues one job', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create();

    RepositorySyncJob::dispatch($repository);
    RepositorySyncJob::dispatch($repository);

    Queue::assertPushed(RepositorySyncJob::class, 1);
});

test('AutofetchRepositoryChangesetsJob does nothing when autofetch_changesets is disabled', function () {
    Queue::fake();
    Repository::factory()->for(Project::factory())->create();

    (new AutofetchRepositoryChangesetsJob)->handle();

    Queue::assertNotPushed(RepositorySyncJob::class);
});

test('AutofetchRepositoryChangesetsJob queues a sync for every repository when enabled', function () {
    Queue::fake();
    Setting::set('autofetch_changesets', true);
    $repositoryA = Repository::factory()->for(Project::factory())->create();
    $repositoryB = Repository::factory()->for(Project::factory())->create();

    (new AutofetchRepositoryChangesetsJob)->handle();

    Queue::assertPushed(RepositorySyncJob::class, 2);
    Queue::assertPushed(RepositorySyncJob::class, fn (RepositorySyncJob $job) => $job->uniqueId() === (string) $repositoryA->id);
    Queue::assertPushed(RepositorySyncJob::class, fn (RepositorySyncJob $job) => $job->uniqueId() === (string) $repositoryB->id);
});

test('a failed sync is logged rather than swallowed silently', function () {
    Log::spy();

    $project = Project::factory()->create();
    $repository = Repository::factory()->for($project)->create(['path' => sys_get_temp_dir().'/does-not-exist-'.uniqid()]);

    (new RepositorySyncJob($repository))->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'RepositorySyncJob failed'
            && $context['repository_id'] === $repository->id
            && $context['error'] === 'boom');
});

test('a repository path outside the configured repositories root is rejected', function () {
    $project = Project::factory()->create();
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', sys_get_temp_dir())
        ->call('save')
        ->assertHasErrors(['path']);

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();
});

test('a path outside the root never reaches the isAvailable check that would shell out to it', function () {
    $project = Project::factory()->create();
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);

    // If the WithinRepositoriesRoot rejection didn't `bail` before the
    // isAvailable() closure, this would try to run git against a path
    // outside the allowed root — exactly what the rule exists to prevent.
    Process::fake(['*' => Process::result(output: 'SHOULD NOT BE CALLED')]);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', sys_get_temp_dir())
        ->call('save')
        ->assertHasErrors(['path']);

    Process::assertNothingRan();
});

test('a path within the root that is not a real repository is rejected with a helpful error', function () {
    $project = Project::factory()->create();
    $manager = repositoryMember($project, ['view_changesets', 'manage_repository']);
    $emptyPath = config('scm.repositories_root').'/not-a-repo-'.uniqid();
    mkdir($emptyPath);

    Livewire::actingAs($manager)
        ->test('repository.form', ['project' => $project])
        ->set('path', $emptyPath)
        ->call('save')
        ->assertHasErrors(['path']);

    expect(Repository::where('project_id', $project->id)->exists())->toBeFalse();

    Process::path(config('scm.repositories_root'))->run(['rm', '-rf', $emptyPath]);
});

test('the diff view is cached rather than re-invoking git on every request', function () {
    $project = Project::factory()->create();
    $user = repositoryMember($project);
    $path = createTestGitRepo(['Initial commit']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);
    app(RepositorySyncService::class)->sync($repository);
    $changeset = $repository->changesets()->firstOrFail();

    $first = Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->get('diff');

    Process::path($path)->run(['git', 'commit', '--allow-empty', '-q', '-m', 'Unrelated'])->throw();
    Process::fake(['*' => Process::result(output: 'SHOULD NOT BE CALLED')]);

    $second = Livewire::actingAs($user)
        ->test('repository.show', ['project' => $project, 'changeset' => $changeset])
        ->get('diff');

    expect($second)->toBe($first)->not->toContain('SHOULD NOT BE CALLED');
});

test('RepositorySyncJob runs the sync service against the given repository', function () {
    $project = Project::factory()->create();
    $path = createTestGitRepo(['Queued sync test']);
    $repository = Repository::factory()->for($project)->create(['path' => $path]);

    RepositorySyncJob::dispatch($repository);

    expect($repository->changesets()->count())->toBe(1);
});
