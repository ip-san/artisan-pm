<?php

use App\Enums\FilterOperator;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Livewire\Livewire;

/**
 * A1-17c: watcher_id, updated_by, last_updated_by, member_of_group,
 * assigned_to_role, author_group, author_role, attachment and
 * attachment_description.
 *
 * @param  array<int, string>  $permissions
 * @param  array<string, mixed>  $roleAttributes
 */
function peopleFilterMember(Project $project, array $permissions = ['view_issues', 'save_queries'], array $roleAttributes = []): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions, ...$roleAttributes]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function peopleFilter(Project $project, ?User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

function peopleFilterAttach(Issue $issue, string $fileName, ?string $description = null): void
{
    $adder = $issue->addMediaFromString('content')->usingFileName($fileName);

    if ($description !== null) {
        $adder->withCustomProperties(['description' => $description]);
    }

    $adder->toMediaCollection('attachments');
}

test('the watcher filter is only offered to a signed-in viewer', function () {
    $project = Project::factory()->create();

    expect(IssueFilterFieldRegistry::forProject($project, null)->has('watcher_id'))->toBeFalse()
        ->and(IssueFilterFieldRegistry::forProject($project, peopleFilterMember($project))->has('watcher_id'))->toBeTrue();
});

test('watching by me always works, watching by others needs view_issue_watchers', function () {
    $project = Project::factory()->create();
    $plain = peopleFilterMember($project);
    $watcherViewer = peopleFilterMember($project, ['view_issues', 'view_issue_watchers']);
    $other = peopleFilterMember($project);
    $mine = Issue::factory()->for($project)->create();
    $theirs = Issue::factory()->for($project)->create();
    $unwatched = Issue::factory()->for($project)->create();
    $mine->watchers()->create(['user_id' => $plain->id]);
    $theirs->watchers()->create(['user_id' => $other->id]);

    expect(peopleFilter($project, $plain, 'watcher_id', FilterOperator::Equals, ['me']))->toBe([$mine->id])
        ->and(peopleFilter($project, $plain, 'watcher_id', FilterOperator::NotEquals, ['me']))->toBe([$theirs->id, $unwatched->id])
        ->and(peopleFilter($project, $plain, 'watcher_id', FilterOperator::Equals, [(string) $other->id]))->toBe([])
        ->and(peopleFilter($project, $watcherViewer, 'watcher_id', FilterOperator::Equals, [(string) $other->id]))->toBe([$theirs->id])
        ->and(peopleFilter($project, $watcherViewer, 'watcher_id', FilterOperator::In, [(string) $other->id, (string) $plain->id]))->toBe([$mine->id, $theirs->id])
        ->and(IssueFilterFieldRegistry::forProject($project, $plain)->get('watcher_id')->options())->toBe(['me' => '<< 自分 >>'])
        ->and(IssueFilterFieldRegistry::forProject($project, $watcherViewer)->get('watcher_id')->options())->toHaveKey($other->id);
});

test('updated by and last updated by only count journals the viewer may read', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project);
    $privileged = peopleFilterMember($project, ['view_issues', 'view_private_notes']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceThenBob = Issue::factory()->for($project)->create();
    $bobPrivately = Issue::factory()->for($project)->create();
    $untouched = Issue::factory()->for($project)->create();
    Journal::create(['issue_id' => $aliceThenBob->id, 'user_id' => $alice->id, 'notes' => 'first']);
    Journal::create(['issue_id' => $aliceThenBob->id, 'user_id' => $bob->id, 'notes' => 'second']);
    Journal::create(['issue_id' => $bobPrivately->id, 'user_id' => $alice->id, 'notes' => 'public']);
    Journal::create(['issue_id' => $bobPrivately->id, 'user_id' => $bob->id, 'notes' => 'hidden', 'private_notes' => true]);

    expect(peopleFilter($project, $viewer, 'updated_by', FilterOperator::Equals, [(string) $bob->id]))->toBe([$aliceThenBob->id])
        ->and(peopleFilter($project, $viewer, 'updated_by', FilterOperator::NotEquals, [(string) $bob->id]))->toBe([$bobPrivately->id, $untouched->id])
        ->and(peopleFilter($project, $privileged, 'updated_by', FilterOperator::Equals, [(string) $bob->id]))->toBe([$aliceThenBob->id, $bobPrivately->id])
        ->and(peopleFilter($project, $viewer, 'last_updated_by', FilterOperator::Equals, [(string) $alice->id]))->toBe([$bobPrivately->id])
        ->and(peopleFilter($project, $privileged, 'last_updated_by', FilterOperator::Equals, [(string) $alice->id]))->toBe([])
        ->and(peopleFilter($project, $viewer, 'last_updated_by', FilterOperator::NotEquals, [(string) $alice->id]))->toBe([$aliceThenBob->id, $untouched->id])
        ->and(peopleFilter($project, $bob, 'last_updated_by', FilterOperator::Equals, ['me']))->toBe([$aliceThenBob->id, $bobPrivately->id]);
});

test('the assignee group filter matches assignees in the chosen visible groups', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project);
    $group = Group::factory()->create();
    $otherGroup = Group::factory()->create();
    $inGroup = User::factory()->create();
    $inOther = User::factory()->create();
    $group->users()->attach($inGroup);
    $otherGroup->users()->attach($inOther);
    $a = Issue::factory()->for($project)->create(['assigned_to_id' => $inGroup->id]);
    $b = Issue::factory()->for($project)->create(['assigned_to_id' => $inOther->id]);
    $c = Issue::factory()->for($project)->create(['assigned_to_id' => $viewer->id]);
    $d = Issue::factory()->for($project)->create(['assigned_to_id' => null]);

    expect(peopleFilter($project, $viewer, 'member_of_group', FilterOperator::Equals, [(string) $group->id]))->toBe([$a->id])
        ->and(peopleFilter($project, $viewer, 'member_of_group', FilterOperator::NotEquals, [(string) $group->id]))->toBe([$b->id, $c->id, $d->id])
        ->and(peopleFilter($project, $viewer, 'member_of_group', FilterOperator::IsNotEmpty))->toBe([$a->id, $b->id])
        ->and(peopleFilter($project, $viewer, 'member_of_group', FilterOperator::IsEmpty))->toBe([$c->id, $d->id]);
});

test('groups the viewer cannot see are neither offered nor honoured', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project, ['view_issues'], ['users_visibility' => 'members_of_visible_projects']);
    $memberGroup = Group::factory()->create();
    $hiddenGroup = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $memberGroup->id]);
    $hiddenUser = User::factory()->create();
    $hiddenGroup->users()->attach($hiddenUser);
    Issue::factory()->for($project)->create(['assigned_to_id' => $hiddenUser->id, 'author_id' => $hiddenUser->id]);

    $options = IssueFilterFieldRegistry::forProject($project, $viewer)->get('member_of_group')->options();

    expect(array_keys($options))->toBe([$memberGroup->id])
        ->and(peopleFilter($project, $viewer, 'member_of_group', FilterOperator::Equals, [(string) $hiddenGroup->id]))->toBe([])
        ->and(peopleFilter($project, $viewer, 'author_group', FilterOperator::Equals, [(string) $hiddenGroup->id]))->toBe([]);
});

test('the author group filter matches authors in the chosen groups', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project);
    $group = Group::factory()->create();
    $author = User::factory()->create();
    $group->users()->attach($author);
    $byGroup = Issue::factory()->for($project)->create(['author_id' => $author->id]);
    $byOther = Issue::factory()->for($project)->create();

    expect(peopleFilter($project, $viewer, 'author_group', FilterOperator::Equals, [(string) $group->id]))->toBe([$byGroup->id])
        ->and(peopleFilter($project, $viewer, 'author_group', FilterOperator::NotEquals, [(string) $group->id]))->toBe([$byOther->id]);
});

test('the role filters match the role the assignee or author holds in the issue project, directly or through a group', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project);
    $developer = Role::factory()->create(['permissions' => ['view_issues']]);
    $reporter = Role::factory()->create(['permissions' => ['view_issues']]);
    $dev = User::factory()->create();
    Member::factory()->for($project)->for($dev)->create()->roles()->attach($developer);
    $groupDev = User::factory()->create();
    $group = Group::factory()->create();
    $group->users()->attach($groupDev);
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id])->roles()->attach($developer);
    $outsider = User::factory()->create();
    Member::factory()->for(Project::factory()->create())->for($outsider)->create()->roles()->attach($developer);

    $byDev = Issue::factory()->for($project)->create(['assigned_to_id' => $dev->id, 'author_id' => $dev->id]);
    $byGroupDev = Issue::factory()->for($project)->create(['assigned_to_id' => $groupDev->id, 'author_id' => $groupDev->id]);
    $byOutsider = Issue::factory()->for($project)->create(['assigned_to_id' => $outsider->id, 'author_id' => $outsider->id]);
    $unassigned = Issue::factory()->for($project)->create(['assigned_to_id' => null, 'author_id' => $outsider->id]);

    expect(peopleFilter($project, $viewer, 'assigned_to_role', FilterOperator::Equals, [(string) $developer->id]))->toBe([$byDev->id, $byGroupDev->id])
        ->and(peopleFilter($project, $viewer, 'assigned_to_role', FilterOperator::Equals, [(string) $reporter->id]))->toBe([])
        ->and(peopleFilter($project, $viewer, 'assigned_to_role', FilterOperator::NotEquals, [(string) $developer->id]))->toBe([$byOutsider->id, $unassigned->id])
        ->and(peopleFilter($project, $viewer, 'assigned_to_role', FilterOperator::IsNotEmpty))->toBe([$byDev->id, $byGroupDev->id])
        ->and(peopleFilter($project, $viewer, 'assigned_to_role', FilterOperator::IsEmpty))->toBe([$byOutsider->id, $unassigned->id])
        ->and(peopleFilter($project, $viewer, 'author_role', FilterOperator::Equals, [(string) $developer->id]))->toBe([$byDev->id, $byGroupDev->id])
        ->and(peopleFilter($project, $viewer, 'author_role', FilterOperator::NotIn, [(string) $developer->id]))->toBe([$byOutsider->id, $unassigned->id]);
});

test('the attachment filters match file names and descriptions', function () {
    $project = Project::factory()->create();
    $viewer = peopleFilterMember($project);
    $log = Issue::factory()->for($project)->create();
    $described = Issue::factory()->for($project)->create();
    $undescribed = Issue::factory()->for($project)->create();
    $bare = Issue::factory()->for($project)->create();
    peopleFilterAttach($log, 'server-ERROR.log');
    peopleFilterAttach($described, 'shot.txt', 'Screenshot of the crash');
    peopleFilterAttach($undescribed, 'notes.txt', '');

    expect(peopleFilter($project, $viewer, 'attachment', FilterOperator::Contains, ['error']))->toBe([$log->id])
        ->and(peopleFilter($project, $viewer, 'attachment', FilterOperator::NotContains, ['error']))->toBe([$described->id, $undescribed->id, $bare->id])
        ->and(peopleFilter($project, $viewer, 'attachment', FilterOperator::IsNotEmpty))->toBe([$log->id, $described->id, $undescribed->id])
        ->and(peopleFilter($project, $viewer, 'attachment', FilterOperator::IsEmpty))->toBe([$bare->id])
        ->and(peopleFilter($project, $viewer, 'attachment_description', FilterOperator::Contains, ['crash']))->toBe([$described->id])
        ->and(peopleFilter($project, $viewer, 'attachment_description', FilterOperator::NotContains, ['nothing']))->toBe([$described->id])
        ->and(peopleFilter($project, $viewer, 'attachment_description', FilterOperator::IsNotEmpty))->toBe([$described->id])
        ->and(peopleFilter($project, $viewer, 'attachment_description', FilterOperator::IsEmpty))->toBe([$log->id, $undescribed->id]);
});

test('the people filters survive a saved query round trip', function () {
    $project = Project::factory()->create();
    $user = peopleFilterMember($project);
    $watched = Issue::factory()->for($project)->create();
    Issue::factory()->for($project)->create();
    $watched->watchers()->create(['user_id' => $user->id]);
    Journal::create(['issue_id' => $watched->id, 'user_id' => $user->id, 'notes' => 'mine']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('addFilter', 'watcher_id')
        ->set('filterOperators.watcher_id', '=')
        ->set('filterValues.watcher_id.0', 'me')
        ->call('addFilter', 'last_updated_by')
        ->set('filterOperators.last_updated_by', '=')
        ->set('filterValues.last_updated_by.0', 'me')
        ->set('newQueryName', 'Watched by me')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Watched by me')->firstOrFail();

    expect($saved->filters['watcher_id']['values'])->toBe(['me']);

    $issues = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('loadQuery', $saved->id)
        ->get('issues');

    expect($issues->pluck('id')->all())->toBe([$watched->id]);
});

test('matching issues from another project or invisible issues never reach the list', function () {
    $project = Project::factory()->create();
    $user = peopleFilterMember($project, ['view_issues'], ['issues_visibility' => 'default']);

    $visible = Issue::factory()->for($project)->create();
    $invisible = Issue::factory()->for($project)->create(['is_private' => true]);
    $elsewhere = Issue::factory()->for(Project::factory()->create())->create();

    foreach ([$visible, $invisible, $elsewhere] as $issue) {
        $issue->watchers()->create(['user_id' => $user->id]);
        Journal::create(['issue_id' => $issue->id, 'user_id' => $user->id, 'notes' => 'x']);
        peopleFilterAttach($issue, 'needle.txt', 'needle');
    }

    foreach ([['watcher_id', '=', 'me'], ['updated_by', '=', 'me'], ['last_updated_by', '=', 'me'], ['attachment', '~', 'needle'], ['attachment_description', '~', 'needle']] as [$key, $operator, $value]) {
        $issues = Livewire::actingAs($user)
            ->test('issues.index', ['project' => $project])
            ->set('statusFilter', 'all')
            ->call('addFilter', $key)
            ->set("filterOperators.{$key}", $operator)
            ->set("filterValues.{$key}.0", $value)
            ->get('issues');

        expect($issues->pluck('id')->all())->toBe([$visible->id]);
    }
});
