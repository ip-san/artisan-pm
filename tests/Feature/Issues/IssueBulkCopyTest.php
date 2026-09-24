<?php

use App\Enums\IssueRelationType;
use App\Enums\UserStatus;
use App\Enums\VersionStatus;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function bulkCopyMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => $permissions])
    );

    return $user;
}

function bulkCopyIssue(Project $project, Tracker $tracker): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'Original subject',
    ]);
}

test('a user with copy_issues can bulk copy selected issues to another project', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $sourceTracker = Tracker::factory()->create();
    $targetTracker = Tracker::factory()->create();
    $source->trackers()->attach($sourceTracker);
    $target->trackers()->attach($targetTracker);

    $user = bulkCopyMember($source, ['view_issues', 'copy_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );

    $issueA = bulkCopyIssue($source, $sourceTracker);
    $issueB = bulkCopyIssue($source, $sourceTracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issueA->id, $issueB->id])
        ->set('bulkCopyToProjectId', $target->id)
        ->set('bulkCopyToTrackerId', $targetTracker->id)
        ->call('applyBulkCopy');

    expect($issueA->fresh()->project_id)->toBe($source->id)
        ->and(Issue::query()->where('project_id', $target->id)->count())->toBe(2)
        ->and(Issue::query()->where('project_id', $target->id)->pluck('subject')->all())
        ->toBe(['Original subject', 'Original subject']);
});

test('copying an issue creates a copied_to relation back to the source', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author);

    $relation = IssueRelation::query()
        ->where('issue_from_id', $source->id)
        ->where('issue_to_id', $copy->id)
        ->first();

    expect($relation)->not->toBeNull()
        ->and($relation->relation_type)->toBe(IssueRelationType::CopiedTo);
});

test('a user without copy_issues cannot bulk copy issues', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );
    $issue = bulkCopyIssue($source, $tracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkCopyToProjectId', $target->id)
        ->set('bulkCopyToTrackerId', $tracker->id)
        ->call('applyBulkCopy')
        ->assertForbidden();

    expect(Issue::query()->where('project_id', $target->id)->count())->toBe(0);
});

test('bulk copy carries over custom field values relevant to the target tracker', function () {
    $source = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues', 'copy_issues', 'add_issues']);

    $issue = bulkCopyIssue($source, $tracker);
    $issue->setCustomFieldValues([$field->id => 'High']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkCopyToProjectId', $source->id)
        ->set('bulkCopyToTrackerId', $tracker->id)
        ->call('applyBulkCopy');

    $copy = Issue::query()->where('id', '!=', $issue->id)->where('project_id', $source->id)->sole();

    expect($copy->customValue($field))->toBe('High');
});

test('bulk copy is not offered without copy_issues', function () {
    $source = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues', 'add_issues']);
    $issue = bulkCopyIssue($source, $tracker);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->assertDontSee('コピーして複製');
});

test('copying an issue duplicates its attachments by default', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);
    $source->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author);

    expect($source->fresh()->getMedia('attachments'))->toHaveCount(1)
        ->and($copy->fresh()->getMedia('attachments'))->toHaveCount(1)
        ->and($copy->fresh()->getMedia('attachments')->first()->file_name)->toBe('notes.txt');
});

test('copying an issue skips attachments when copyAttachments is false', function () {
    Storage::fake('local');

    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);
    $source->addMedia(UploadedFile::fake()->create('notes.txt', 10))->toMediaCollection('attachments');

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author, copyAttachments: false);

    expect($copy->fresh()->getMedia('attachments'))->toBeEmpty();
});

test('copying an issue duplicates its watchers by default, but only active ones', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);
    $activeWatcher = User::factory()->create(['status' => UserStatus::Active]);
    $lockedWatcher = User::factory()->create(['status' => UserStatus::Locked]);
    $source->watchers()->create(['user_id' => $activeWatcher->id]);
    $source->watchers()->create(['user_id' => $lockedWatcher->id]);

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author);

    $copyWatcherIds = $copy->fresh()->watchers->pluck('user_id');

    expect($copyWatcherIds)->toContain($activeWatcher->id)
        ->not->toContain($lockedWatcher->id);
});

test('copying an issue skips watchers when copyWatchers is false', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $author = User::factory()->create();
    $source = bulkCopyIssue($project, $tracker);
    $watcher = User::factory()->create(['status' => UserStatus::Active]);
    $source->watchers()->create(['user_id' => $watcher->id]);

    $copy = app(IssueService::class)->copy($source, $project, $tracker->id, $author, copyWatchers: false);

    expect($copy->fresh()->watchers->pluck('user_id'))->not->toContain($watcher->id);
});

test('the bulk copy form offers checkboxes to copy attachments and watchers, checked by default', function () {
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $user = bulkCopyMember($source, ['view_issues', 'copy_issues']);
    Member::factory()->for($target)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues', 'add_issues']])
    );
    $issue = bulkCopyIssue($source, $tracker);

    $component = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $source])
        ->set('selected', [$issue->id])
        ->set('bulkCopyToProjectId', $target->id);

    expect($component->get('bulkCopyAttachments'))->toBeTrue()
        ->and($component->get('bulkCopyWatchers'))->toBeTrue();
});

/**
 * @return array{source: Project, target: Project, tracker: Tracker, actor: User}
 */
function bulkCopyProjects(): array
{
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    return ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => User::factory()->admin()->create()];
}

test('a copy keeps a version that is still open and shared with the target project (A1-47)', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = bulkCopyProjects();
    $shared = Version::factory()->for($source)->create(['sharing' => 'system']);
    $default = Version::factory()->for($target)->create();
    $target->update(['default_version_id' => $default->id]);
    $issue = bulkCopyIssue($source, $tracker);
    $issue->update(['fixed_version_id' => $shared->id]);

    $copy = app(IssueService::class)->copy($issue, $target, $tracker->id, $actor);

    expect($copy->fixed_version_id)->toBe($shared->id);
});

test('a copy takes the target default version when its own is not usable there (A1-47)', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = bulkCopyProjects();
    $own = Version::factory()->for($source)->create();
    $closedShared = Version::factory()->for($source)->create(['sharing' => 'system', 'status' => VersionStatus::Closed]);
    $default = Version::factory()->for($target)->create();
    $target->update(['default_version_id' => $default->id]);
    $unversioned = bulkCopyIssue($source, $tracker);
    $notShared = bulkCopyIssue($source, $tracker);
    $notShared->update(['fixed_version_id' => $own->id]);
    $closed = bulkCopyIssue($source, $tracker);
    $closed->update(['fixed_version_id' => $closedShared->id]);

    foreach ([$unversioned, $notShared, $closed] as $issue) {
        expect(app(IssueService::class)->copy($issue, $target, $tracker->id, $actor)->fixed_version_id)->toBe($default->id);
    }
});

test('a copy within the project keeps its version, and one without a version stays without (A1-47)', function () {
    ['source' => $source, 'tracker' => $tracker, 'actor' => $actor] = bulkCopyProjects();
    $version = Version::factory()->for($source)->create();
    $default = Version::factory()->for($source)->create();
    $source->update(['default_version_id' => $default->id]);
    $versioned = bulkCopyIssue($source, $tracker);
    $versioned->update(['fixed_version_id' => $version->id]);
    $unversioned = bulkCopyIssue($source, $tracker);

    expect(app(IssueService::class)->copy($versioned, $source, $tracker->id, $actor)->fixed_version_id)->toBe($version->id)
        ->and(app(IssueService::class)->copy($unversioned, $source, $tracker->id, $actor)->fixed_version_id)->toBeNull();
});

test('a copy keeps its category within the project and takes the same-named one in another project (A1-47)', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = bulkCopyProjects();
    $backend = IssueCategory::factory()->for($source)->create(['name' => 'Backend']);
    $frontend = IssueCategory::factory()->for($source)->create(['name' => 'Frontend']);
    $targetBackend = IssueCategory::factory()->for($target)->create(['name' => 'Backend']);
    $withBackend = bulkCopyIssue($source, $tracker);
    $withBackend->update(['category_id' => $backend->id]);
    $withFrontend = bulkCopyIssue($source, $tracker);
    $withFrontend->update(['category_id' => $frontend->id]);

    expect(app(IssueService::class)->copy($withBackend, $source, $tracker->id, $actor)->category_id)->toBe($backend->id)
        ->and(app(IssueService::class)->copy($withBackend, $target, $tracker->id, $actor)->category_id)->toBe($targetBackend->id)
        ->and(app(IssueService::class)->copy($withFrontend, $target, $tracker->id, $actor)->category_id)->toBeNull();
});

test('copied subtasks take the target default only in place of an open version, and match categories by name (A1-47)', function () {
    ['source' => $source, 'target' => $target, 'tracker' => $tracker, 'actor' => $actor] = bulkCopyProjects();
    $openOwn = Version::factory()->for($source)->create();
    $closedOwn = Version::factory()->for($source)->create(['status' => VersionStatus::Closed]);
    $default = Version::factory()->for($target)->create();
    $target->update(['default_version_id' => $default->id]);
    $category = IssueCategory::factory()->for($source)->create(['name' => 'Backend']);
    $targetCategory = IssueCategory::factory()->for($target)->create(['name' => 'Backend']);
    $root = bulkCopyIssue($source, $tracker);
    foreach (['open' => $openOwn->id, 'closed' => $closedOwn->id, 'none' => null] as $subject => $versionId) {
        Issue::factory()->for($source)->create([
            'tracker_id' => $tracker->id, 'status_id' => $root->status_id, 'priority_id' => $root->priority_id,
            'parent_id' => $root->id, 'subject' => $subject, 'fixed_version_id' => $versionId, 'category_id' => $category->id,
        ]);
    }

    app(IssueService::class)->copy($root, $target, $tracker->id, $actor, copySubtasks: true);
    $copies = Issue::query()->where('project_id', $target->id)->whereNotNull('parent_id')->get()->keyBy('subject');

    expect($copies['open']->fixed_version_id)->toBe($default->id)
        ->and($copies['closed']->fixed_version_id)->toBeNull()
        ->and($copies['none']->fixed_version_id)->toBeNull()
        ->and($copies->pluck('category_id')->unique()->all())->toBe([$targetCategory->id]);
});
