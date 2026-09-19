<?php

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use App\Services\RepositorySyncService;
use Livewire\Livewire;

/**
 * @return array{0: Project, 1: IssueStatus}
 */
function commitRuleProject(): array
{
    $project = Project::factory()->create();
    $committer = User::factory()->create(['email' => 'test@example.com']);
    Member::factory()->for($project)->for($committer)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'edit_issues', 'view_changesets']])
    );

    return [$project, IssueStatus::factory()->closed()->create()];
}

function commitRuleSync(Project $project, string $message): void
{
    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo([$message])]);
    app(RepositorySyncService::class)->sync($repository);
}

test('a rule can set the done ratio together with, or instead of, a status', function () {
    [$project, $closed] = commitRuleProject();
    $issue = Issue::factory()->for($project)->create(['done_ratio' => 0]);
    $other = Issue::factory()->for($project)->create(['done_ratio' => 0]);
    $originalStatus = $other->status_id;
    Setting::set('commit_fixing_keyword_rules', [
        ['keywords' => 'closes', 'status_id' => $closed->id, 'done_ratio' => 100, 'if_tracker_id' => null],
        ['keywords' => 'progress', 'status_id' => null, 'done_ratio' => 60, 'if_tracker_id' => null],
    ]);

    commitRuleSync($project, "Closes #{$issue->id} and progress #{$other->id}");

    expect($issue->fresh()->only(['status_id', 'done_ratio']))->toBe(['status_id' => $closed->id, 'done_ratio' => 100])
        ->and($other->fresh()->only(['status_id', 'done_ratio']))->toBe(['status_id' => $originalStatus, 'done_ratio' => 60]);
});

test('a rule limited to a tracker only applies to that tracker and the next matching rule wins otherwise', function () {
    [$project, $closed] = commitRuleProject();
    $bugTracker = Tracker::factory()->create();
    $featureTracker = Tracker::factory()->create();
    $inProgress = IssueStatus::factory()->create();
    $bug = Issue::factory()->for($project)->create(['tracker_id' => $bugTracker->id]);
    $feature = Issue::factory()->for($project)->create(['tracker_id' => $featureTracker->id]);
    Setting::set('commit_fixing_keyword_rules', [
        ['keywords' => 'fixes', 'status_id' => $closed->id, 'done_ratio' => null, 'if_tracker_id' => $bugTracker->id],
        ['keywords' => 'fixes', 'status_id' => $inProgress->id, 'done_ratio' => null, 'if_tracker_id' => null],
    ]);

    commitRuleSync($project, "Fixes #{$bug->id}, #{$feature->id}");

    expect($bug->fresh()->status_id)->toBe($closed->id)
        ->and($feature->fresh()->status_id)->toBe($inProgress->id);
});

test('a rule for another tracker leaves the issue alone', function () {
    [$project, $closed] = commitRuleProject();
    $issue = Issue::factory()->for($project)->create();
    $original = $issue->status_id;
    Setting::set('commit_fixing_keyword_rules', [
        ['keywords' => 'fixes', 'status_id' => $closed->id, 'done_ratio' => null, 'if_tracker_id' => Tracker::factory()->create()->id],
    ]);

    commitRuleSync($project, "Fixes #{$issue->id}");

    expect($issue->fresh()->status_id)->toBe($original);
});

test('with specific reference keywords only keyword-prefixed ids are linked', function () {
    [$project] = commitRuleProject();
    $linked = Issue::factory()->for($project)->create();
    $bare = Issue::factory()->for($project)->create();
    $fixed = Issue::factory()->for($project)->create();
    Setting::set('commit_ref_keywords', 'refs, references');
    Setting::set('commit_fixing_keyword_rules', [['keywords' => 'fixes', 'status_id' => IssueStatus::factory()->closed()->create()->id, 'done_ratio' => null, 'if_tracker_id' => null]]);

    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo(["Refs #{$linked->id}. Also mentions #{$bare->id}. Fixes #{$fixed->id}"])]);
    app(RepositorySyncService::class)->sync($repository);

    $changeset = $repository->changesets()->firstOrFail();
    expect($changeset->issues()->pluck('issues.id')->sort()->values()->all())->toBe(collect([$linked->id, $fixed->id])->sort()->values()->all());
});

test('the default wildcard keeps linking every id', function () {
    [$project] = commitRuleProject();
    $issue = Issue::factory()->for($project)->create();

    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo(["Mentions #{$issue->id} in passing"])]);
    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues()->pluck('issues.id')->all())->toBe([$issue->id]);
});

test('an empty reference keyword list links nothing unless a fixing keyword is used', function () {
    [$project] = commitRuleProject();
    $issue = Issue::factory()->for($project)->create();
    Setting::set('commit_ref_keywords', '');

    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo(["Refs #{$issue->id}"])]);
    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues()->count())->toBe(0);
});

test('without cross-project references only the repository project, its parents and children are reachable', function () {
    [$project, $closed] = commitRuleProject();
    $child = Project::factory()->create(['parent_id' => $project->id]);
    $stranger = Project::factory()->create();
    $inside = Issue::factory()->for($project)->create();
    $inChild = Issue::factory()->for($child)->create();
    $elsewhere = Issue::factory()->for($stranger)->create();
    $originalStatus = $elsewhere->status_id;
    Setting::set('commit_cross_project_ref', false);
    Setting::set('commit_fixing_keyword_rules', [['keywords' => 'fixes', 'status_id' => $closed->id, 'done_ratio' => null, 'if_tracker_id' => null]]);

    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo(["Fixes #{$inside->id}, #{$inChild->id}, #{$elsewhere->id}"])]);
    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues()->pluck('issues.id')->sort()->values()->all())->toBe(collect([$inside->id, $inChild->id])->sort()->values()->all())
        ->and($elsewhere->fresh()->status_id)->toBe($originalStatus);
});

test('cross-project references stay allowed by default', function () {
    [$project] = commitRuleProject();
    $elsewhere = Issue::factory()->for(Project::factory()->create())->create();

    $repository = Repository::factory()->for($project)->create(['path' => createTestGitRepo(["See #{$elsewhere->id}"])]);
    app(RepositorySyncService::class)->sync($repository);

    expect($repository->changesets()->firstOrFail()->issues()->pluck('issues.id')->all())->toBe([$elsewhere->id]);
});

test('the settings form saves the new commit options and validates rule rows', function () {
    $admin = User::factory()->admin()->create();
    $status = IssueStatus::factory()->closed()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('settings.index')
        ->set('commit_ref_keywords', 'refs, IssueID')
        ->set('commit_cross_project_ref', false)
        ->set('commit_fixing_keyword_rules', [
            ['keywords' => 'fixes', 'status_id' => $status->id, 'done_ratio' => '100', 'if_tracker_id' => $tracker->id],
            ['keywords' => 'wip', 'status_id' => '', 'done_ratio' => '50', 'if_tracker_id' => ''],
            ['keywords' => '', 'status_id' => '', 'done_ratio' => '', 'if_tracker_id' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::get('commit_ref_keywords'))->toBe('refs, IssueID')
        ->and(Setting::get('commit_cross_project_ref'))->toBeFalse()
        ->and(Setting::get('commit_fixing_keyword_rules'))->toBe([
            ['keywords' => 'fixes', 'status_id' => $status->id, 'done_ratio' => 100, 'if_tracker_id' => $tracker->id],
            ['keywords' => 'wip', 'status_id' => null, 'done_ratio' => 50, 'if_tracker_id' => null],
        ]);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('commit_fixing_keyword_rules', [['keywords' => 'noop', 'status_id' => '', 'done_ratio' => '', 'if_tracker_id' => '']])
        ->call('save')
        ->assertHasErrors(['commit_fixing_keyword_rules.0.status_id']);

    Livewire::actingAs($admin)->test('settings.index')
        ->set('commit_fixing_keyword_rules', [['keywords' => 'x', 'status_id' => '', 'done_ratio' => '150', 'if_tracker_id' => '']])
        ->call('save')
        ->assertHasErrors(['commit_fixing_keyword_rules.0.done_ratio']);
});
