<?php

use App\Enums\CustomizableType;
use App\Enums\QueryType;
use App\Enums\VersionSharing;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Support\Query\FilterSelectOptions;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Livewire\Livewire;

/**
 * A15-20: filters on the issue's related objects' custom fields —
 * project.cf_N, author.cf_N, assigned_to.cf_N and fixed_version.cf_N in
 * Redmine's IssueQuery, keyed project_cf_N/author_cf_N/assigned_to_cf_N/
 * fixed_version_cf_N here (a dot would split the key in the list's
 * Livewire/URL state, like every other association filter). They follow
 * the same role visibility (A1-37) as the issue's own custom fields for
 * project/version fields, and A2-08b's admin-only judgment call for user
 * fields (author/assigned_to).
 *
 * @return array{project: Project, tracker: Tracker, viewer: User, insider: User, insiderRole: Role, admin: User}
 */
function relCfFixture(): array
{
    $project = Project::factory()->create(['name' => 'Related CF project']);
    $tracker = Tracker::factory()->create(['name' => 'Bug']);
    $project->trackers()->attach($tracker);
    $plain = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'save_queries'], 'issues_visibility' => 'all']);
    $insiderRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues', 'save_queries'], 'issues_visibility' => 'all']);
    $viewer = User::factory()->create();
    Member::factory()->for($project)->for($viewer)->create()->roles()->attach($plain);
    $insider = User::factory()->create();
    Member::factory()->for($project)->for($insider)->create()->roles()->attach($insiderRole);

    return ['project' => $project->fresh(), 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole, 'admin' => User::factory()->admin()->create()];
}

function relCfIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

/**
 * Writes custom values as an administrator (setCustomFieldValues() keeps
 * only the fields the signed-in user may see).
 *
 * @param  array<int, mixed>  $values
 */
function relCfSet(object $model, array $values): void
{
    auth()->setUser(User::factory()->admin()->create());
    $model->setCustomFieldValues($values);
    auth()->forgetUser();
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function relCfFilter(Project $project, User $viewer, string $key, string $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('the project, author, assigned_to and fixed_version custom fields filter the issues', function () {
    ['project' => $project, 'tracker' => $tracker, 'admin' => $admin] = relCfFixture();
    $projectField = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region', 'is_filter' => true]);
    $authorField = CustomField::factory()->list(['blue', 'green'])->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $versionField = CustomField::factory()->list(['high', 'low'])->create(['customized_type' => CustomizableType::Version, 'name' => 'Risk', 'is_filter' => true]);
    $version = Version::factory()->for($project)->create();
    relCfSet($project, [$projectField->id => 'emea']);
    relCfSet($version, [$versionField->id => 'high']);

    $author = User::factory()->create(['login' => 'alice']);
    Member::factory()->for($project)->for($author)->create();
    relCfSet($author, [$authorField->id => 'blue']);

    $assignee = User::factory()->create(['login' => 'bob']);
    Member::factory()->for($project)->for($assignee)->create();
    relCfSet($assignee, [$authorField->id => 'green']);

    $matching = relCfIssue($project, $tracker, ['author_id' => $author->id, 'assigned_to_id' => $assignee->id, 'fixed_version_id' => $version->id]);
    $other = relCfIssue($project, $tracker);

    $keys = IssueFilterFieldRegistry::forProject($project, $admin)->keys();

    expect($keys)->toContain("project_cf_{$projectField->id}", "author_cf_{$authorField->id}", "assigned_to_cf_{$authorField->id}", "fixed_version_cf_{$versionField->id}")
        ->and(relCfFilter($project, $admin, "project_cf_{$projectField->id}", '=', ['emea']))->toBe([$matching->id, $other->id])
        ->and(relCfFilter($project, $admin, "author_cf_{$authorField->id}", '=', ['blue']))->toBe([$matching->id])
        ->and(relCfFilter($project, $admin, "author_cf_{$authorField->id}", '!in', ['blue']))->toBe([$other->id])
        ->and(relCfFilter($project, $admin, "assigned_to_cf_{$authorField->id}", '=', ['green']))->toBe([$matching->id])
        ->and(relCfFilter($project, $admin, "fixed_version_cf_{$versionField->id}", '=', ['high']))->toBe([$matching->id])
        ->and(relCfFilter($project, $admin, "fixed_version_cf_{$versionField->id}", '!in', ['high']))->toBe([$other->id]);
});

test('a group-assigned issue does not error and never matches the assigned_to custom field filter', function () {
    ['project' => $project, 'tracker' => $tracker, 'admin' => $admin] = relCfFixture();
    $userField = CustomField::factory()->list(['blue'])->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $group = Group::factory()->create();
    Member::factory()->for($project)->create(['user_id' => null, 'group_id' => $group->id]);
    $groupAssigned = relCfIssue($project, $tracker, ['assigned_to_id' => null, 'assigned_to_group_id' => $group->id]);
    $unassigned = relCfIssue($project, $tracker);
    $assignee = User::factory()->create();
    Member::factory()->for($project)->for($assignee)->create();
    relCfSet($assignee, [$userField->id => 'blue']);
    $userAssigned = relCfIssue($project, $tracker, ['assigned_to_id' => $assignee->id]);

    expect(fn () => relCfFilter($project, $admin, "assigned_to_cf_{$userField->id}", '=', ['blue']))->not->toThrow(Throwable::class)
        ->and(relCfFilter($project, $admin, "assigned_to_cf_{$userField->id}", '=', ['blue']))->toBe([$userAssigned->id])
        ->and(relCfFilter($project, $admin, "assigned_to_cf_{$userField->id}", '!in', ['blue']))->toBe([$groupAssigned->id, $unassigned->id]);
});

test('a fixed_version custom field filter matches through a version shared from another project', function () {
    // The version lives on the parent, not on the child whose list is
    // being filtered — version sharing (VersionSharing) lets a
    // subproject's issue target it anyway, so the field's visibility must
    // be worked out from the version's own project, not the list's scope
    // projects (which here is only the child).
    $admin = User::factory()->admin()->create();
    $parent = Project::factory()->create(['name' => 'Parent project']);
    $child = Project::factory()->create(['name' => 'Child project', 'parent_id' => $parent->id]);
    $tracker = Tracker::factory()->create();
    $parent->trackers()->attach($tracker);
    $child->trackers()->attach($tracker);
    $child->refresh();

    $versionField = CustomField::factory()->list(['high', 'low'])->create(['customized_type' => CustomizableType::Version, 'name' => 'Risk', 'is_filter' => true]);
    $sharedVersion = Version::factory()->for($parent)->create(['sharing' => VersionSharing::Hierarchy]);
    relCfSet($sharedVersion, [$versionField->id => 'high']);
    $issue = relCfIssue($child, $tracker, ['fixed_version_id' => $sharedVersion->id]);

    expect(IssueFilterFieldRegistry::forProject($child, $admin)->keys())->toContain("fixed_version_cf_{$versionField->id}")
        ->and(relCfFilter($child, $admin, "fixed_version_cf_{$versionField->id}", '=', ['high']))->toBe([$issue->id]);
});

test('project and version custom fields are neither offered nor applied to a viewer without their role', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole] = relCfFixture();
    $secretProject = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Budget', 'is_filter' => true]);
    $secretProject->roles()->attach($insiderRole);
    $secretVersion = CustomField::factory()->create(['customized_type' => CustomizableType::Version, 'name' => 'Confidential', 'is_filter' => true]);
    $secretVersion->roles()->attach($insiderRole);
    $version = Version::factory()->for($project)->create();
    relCfSet($project, [$secretProject->id => 'secret-budget']);
    relCfSet($version, [$secretVersion->id => 'top-secret']);
    $issue = relCfIssue($project, $tracker, ['fixed_version_id' => $version->id]);

    $viewerKeys = IssueFilterFieldRegistry::forProject($project, $viewer)->keys();
    $insiderKeys = IssueFilterFieldRegistry::forProject($project, $insider)->keys();

    expect($viewerKeys)->not->toContain("project_cf_{$secretProject->id}", "fixed_version_cf_{$secretVersion->id}")
        ->and($insiderKeys)->toContain("project_cf_{$secretProject->id}", "fixed_version_cf_{$secretVersion->id}")
        // A value that does not match: the viewer's hidden filter is
        // ignored (every issue in scope still comes back), the insider's
        // is applied (nothing matches).
        ->and(relCfFilter($project, $viewer, "project_cf_{$secretProject->id}", '~', ['nothing-like-this']))->toBe([$issue->id])
        ->and(relCfFilter($project, $insider, "project_cf_{$secretProject->id}", '~', ['nothing-like-this']))->toBe([])
        ->and(relCfFilter($project, $viewer, "fixed_version_cf_{$secretVersion->id}", '~', ['nothing-like-this']))->toBe([$issue->id])
        ->and(relCfFilter($project, $insider, "fixed_version_cf_{$secretVersion->id}", '~', ['nothing-like-this']))->toBe([]);
});

test('author and assigned_to custom fields are offered and honoured to administrators only', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer, 'admin' => $admin] = relCfFixture();
    $userField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $author = User::factory()->create();
    Member::factory()->for($project)->for($author)->create();
    relCfSet($author, [$userField->id => 'blue']);
    $matching = relCfIssue($project, $tracker, ['author_id' => $author->id]);
    // A second issue whose author has no value for the field, so ignoring
    // the filter (both issues) is distinguishable from applying it (one).
    $other = relCfIssue($project, $tracker);

    $viewerKeys = IssueFilterFieldRegistry::forProject($project, $viewer)->keys();
    $adminKeys = IssueFilterFieldRegistry::forProject($project, $admin)->keys();

    expect($viewerKeys)->not->toContain("author_cf_{$userField->id}", "assigned_to_cf_{$userField->id}")
        ->and($adminKeys)->toContain("author_cf_{$userField->id}", "assigned_to_cf_{$userField->id}")
        // A hand-crafted filter key on a viewer that is not offered the
        // field must not be honoured either: it is ignored, so both issues
        // come back, where the admin's applied filter returns one.
        ->and(relCfFilter($project, $viewer, "author_cf_{$userField->id}", '=', ['blue']))->toBe([$matching->id, $other->id])
        ->and(relCfFilter($project, $admin, "author_cf_{$userField->id}", '=', ['blue']))->toBe([$matching->id]);
});

test('the add filter select groups the related custom field filters under their association', function () {
    ['project' => $project, 'admin' => $admin] = relCfFixture();
    $projectField = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region', 'is_filter' => true]);
    $authorField = CustomField::factory()->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $versionField = CustomField::factory()->create(['customized_type' => CustomizableType::Version, 'name' => 'Risk', 'is_filter' => true]);

    $options = FilterSelectOptions::grouped(IssueFilterFieldRegistry::forProject($project, $admin));

    expect(array_keys($options['groups'][__('プロジェクト')]))->toContain("project_cf_{$projectField->id}")
        ->and(array_keys($options['groups'][__('作成者')]))->toContain("author_cf_{$authorField->id}")
        ->and(array_keys($options['groups'][__('担当者')]))->toContain("assigned_to_cf_{$authorField->id}")
        ->and(array_keys($options['groups'][__('対象バージョン')]))->toContain("fixed_version_cf_{$versionField->id}");
});

test('a saved query round trip through the issue list preserves the related custom field filters', function () {
    ['project' => $project, 'tracker' => $tracker, 'admin' => $admin] = relCfFixture();
    $projectField = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region', 'is_filter' => true]);
    relCfSet($project, [$projectField->id => 'emea']);
    $matching = relCfIssue($project, $tracker);
    $other = Project::factory()->create();
    $other->trackers()->attach($tracker);
    relCfSet($other, [$projectField->id => 'apac']);

    $key = "project_cf_{$projectField->id}";
    $savedQuery = SavedQuery::create([
        'name' => 'Region filter',
        'type' => QueryType::Issue->value,
        'user_id' => $admin->id,
        'project_id' => $project->id,
        'visibility' => 'private',
        'filters' => [$key => ['operator' => '=', 'values' => ['emea']]],
        'column_names' => ['subject', $key],
    ]);

    $list = Livewire::actingAs($admin)->test('issues.index', ['project' => $project])
        ->call('loadQuery', $savedQuery->id)
        ->set('statusFilter', 'all');

    expect($list->get('activeFilterKeys'))->toContain($key)
        ->and($list->get('filterOperators')[$key])->toBe('=')
        ->and($list->get('filterValues')[$key])->toBe(['emea'])
        ->and($list->get('issues')->getCollection()->pluck('id')->all())->toBe([$matching->id]);
});

test('the REST issue list honours project_cf and respects the same role visibility', function () {
    ['project' => $project, 'tracker' => $tracker, 'viewer' => $viewer, 'insider' => $insider, 'insiderRole' => $insiderRole] = relCfFixture();
    $plainRole = Role::factory()->create(['permissions' => ['view_project', 'view_issues'], 'issues_visibility' => 'all']);
    $field = CustomField::factory()->create(['customized_type' => CustomizableType::Project, 'name' => 'Region', 'is_filter' => true]);
    $field->roles()->attach($insiderRole);
    relCfSet($project, [$field->id => 'emea']);
    $matching = relCfIssue($project, $tracker);

    $other = Project::factory()->create();
    $other->trackers()->attach($tracker);
    relCfSet($other, [$field->id => 'apac']);
    Member::factory()->for($other)->for($viewer)->create()->roles()->attach($plainRole);
    Member::factory()->for($other)->for($insider)->create()->roles()->attach($insiderRole);
    $otherIssue = relCfIssue($other, $tracker);

    $key = "project_cf_{$field->id}";
    $ids = function (User $user) use ($key): array {
        app('auth')->forgetGuards();
        $response = $this->withHeaders(['X-Redmine-API-Key' => $user->regenerateApiKey()])
            ->getJson("/api/v1/issues?f[]={$key}&op[{$key}]=~&v[{$key}][]=emea")
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    };

    // The insider sees the field in both projects (insiderRole grants it
    // there too): the filter applies and only the matching issue is
    // returned. The plain viewer, a member of $other with the plain role,
    // does not see the field anywhere: the filter is ignored entirely, as
    // A1-37 established for the issue's own custom fields, so every issue
    // in the projects the viewer belongs to comes back unfiltered.
    expect($ids($insider))->toBe([$matching->id])
        ->and($ids($viewer))->toBe(collect([$matching->id, $otherIssue->id])->sort()->values()->all());
});

test('"none" on the assignee\'s custom field matches an assignee without a value, not an issue without an assignee (A17-07)', function () {
    ['project' => $project, 'tracker' => $tracker, 'admin' => $admin] = relCfFixture();
    $field = CustomField::factory()->list(['blue', 'green'])->create(['customized_type' => CustomizableType::User, 'name' => 'Team', 'is_filter' => true]);
    $withValue = User::factory()->create();
    Member::factory()->for($project)->for($withValue)->create();
    relCfSet($withValue, [$field->id => 'blue']);
    $withoutValue = User::factory()->create();
    Member::factory()->for($project)->for($withoutValue)->create();

    $blue = relCfIssue($project, $tracker, ['assigned_to_id' => $withValue->id]);
    $blank = relCfIssue($project, $tracker, ['assigned_to_id' => $withoutValue->id]);
    $unassigned = relCfIssue($project, $tracker);

    expect(relCfFilter($project, $admin, "assigned_to_cf_{$field->id}", 'empty'))->toBe([$blank->id])
        ->and(relCfFilter($project, $admin, "assigned_to_cf_{$field->id}", '!in', ['blue']))->toBe([$blank->id, $unassigned->id]);
});
