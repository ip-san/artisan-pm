<?php

use App\Enums\FilterOperator;
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
 * A1-17a: filters on the issue's own columns (description, notes,
 * estimated_hours, is_private, issue_id, parent_id).
 *
 * @param  array<int, string>  $permissions
 */
function ownColumnMember(Project $project, array $permissions = ['view_issues', 'save_queries']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

/**
 * @param  array<int, mixed>  $values
 * @return array<int, int>
 */
function ownColumnFilter(Project $project, ?User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

test('the description filter supports contains, does not contain, none and any', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    $crash = Issue::factory()->for($project)->create(['description' => 'The app CRASHES on login']);
    $other = Issue::factory()->for($project)->create(['description' => 'Works fine']);
    $blank = Issue::factory()->for($project)->create(['description' => '']);
    $null = Issue::factory()->for($project)->create(['description' => null]);

    expect(ownColumnFilter($project, $viewer, 'description', FilterOperator::Contains, ['crashes']))->toBe([$crash->id])
        ->and(ownColumnFilter($project, $viewer, 'description', FilterOperator::NotContains, ['crashes']))->toBe([$other->id, $blank->id])
        ->and(ownColumnFilter($project, $viewer, 'description', FilterOperator::IsEmpty))->toBe([$blank->id, $null->id])
        ->and(ownColumnFilter($project, $viewer, 'description', FilterOperator::IsNotEmpty))->toBe([$crash->id, $other->id]);
});

test('the notes filter matches issues by the text of their comments', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    $withNote = Issue::factory()->for($project)->create();
    $otherNote = Issue::factory()->for($project)->create();
    $noNotes = Issue::factory()->for($project)->create();
    Journal::create(['issue_id' => $withNote->id, 'user_id' => $viewer->id, 'notes' => 'Reproduced on STAGING']);
    Journal::create(['issue_id' => $otherNote->id, 'user_id' => $viewer->id, 'notes' => 'Something else']);
    Journal::create(['issue_id' => $noNotes->id, 'user_id' => $viewer->id, 'notes' => '']);

    expect(ownColumnFilter($project, $viewer, 'notes', FilterOperator::Contains, ['staging']))->toBe([$withNote->id])
        ->and(ownColumnFilter($project, $viewer, 'notes', FilterOperator::NotContains, ['staging']))->toBe([$otherNote->id, $noNotes->id])
        ->and(ownColumnFilter($project, $viewer, 'notes', FilterOperator::IsNotEmpty))->toBe([$withNote->id, $otherNote->id])
        ->and(ownColumnFilter($project, $viewer, 'notes', FilterOperator::IsEmpty))->toBe([$noNotes->id]);
});

test('the notes filter does not look into private notes the viewer may not read', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    $privileged = ownColumnMember($project, ['view_issues', 'view_private_notes']);
    $noteAuthor = User::factory()->create();
    $issue = Issue::factory()->for($project)->create();
    Journal::create(['issue_id' => $issue->id, 'user_id' => $noteAuthor->id, 'notes' => 'secret workaround', 'private_notes' => true]);

    expect(ownColumnFilter($project, $viewer, 'notes', FilterOperator::Contains, ['secret']))->toBe([])
        ->and(ownColumnFilter($project, $viewer, 'notes', FilterOperator::IsNotEmpty))->toBe([])
        ->and(ownColumnFilter($project, $privileged, 'notes', FilterOperator::Contains, ['secret']))->toBe([$issue->id])
        ->and(ownColumnFilter($project, $noteAuthor, 'notes', FilterOperator::Contains, ['secret']))->toBe([$issue->id]);
});

test('the estimated time filter compares hours and tests for a value', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    $small = Issue::factory()->for($project)->create(['estimated_hours' => 1.5]);
    $large = Issue::factory()->for($project)->create(['estimated_hours' => 8]);
    $none = Issue::factory()->for($project)->create(['estimated_hours' => null]);

    expect(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::Equals, ['1.5']))->toBe([$small->id])
        ->and(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::GreaterOrEqual, ['2']))->toBe([$large->id])
        ->and(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::LessOrEqual, ['2']))->toBe([$small->id])
        ->and(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::Between, ['1', '8']))->toBe([$small->id, $large->id])
        ->and(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::IsEmpty))->toBe([$none->id])
        ->and(ownColumnFilter($project, $viewer, 'estimated_hours', FilterOperator::IsNotEmpty))->toBe([$small->id, $large->id]);
});

test('the private filter is only offered to someone who may make issues private', function () {
    $project = Project::factory()->create();
    $plain = ownColumnMember($project);
    $setter = ownColumnMember($project, ['view_issues', 'set_own_issues_private']);

    expect(IssueFilterFieldRegistry::forProject($project, $plain)->has('is_private'))->toBeFalse()
        ->and(IssueFilterFieldRegistry::forProject($project, $setter)->has('is_private'))->toBeTrue()
        ->and(IssueFilterFieldRegistry::forProject($project, null)->has('is_private'))->toBeFalse();
});

test('the private filter matches private or public issues', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project, ['view_issues', 'set_issues_private']);
    $private = Issue::factory()->for($project)->create(['is_private' => true]);
    $public = Issue::factory()->for($project)->create(['is_private' => false]);

    expect(ownColumnFilter($project, $viewer, 'is_private', FilterOperator::Equals, ['1']))->toBe([$private->id])
        ->and(ownColumnFilter($project, $viewer, 'is_private', FilterOperator::Equals, ['0']))->toBe([$public->id])
        ->and(ownColumnFilter($project, $viewer, 'is_private', FilterOperator::NotEquals, ['1']))->toBe([$public->id]);
});

test('the issue filter takes a list of ids or compares the id', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    [$first, $second, $third] = Issue::factory()->for($project)->count(3)->create()->all();

    expect(ownColumnFilter($project, $viewer, 'issue_id', FilterOperator::Equals, ["{$first->id}, {$third->id}"]))->toBe([$first->id, $third->id])
        ->and(ownColumnFilter($project, $viewer, 'issue_id', FilterOperator::Equals, ['none']))->toBe([])
        ->and(ownColumnFilter($project, $viewer, 'issue_id', FilterOperator::GreaterOrEqual, [$second->id]))->toBe([$second->id, $third->id])
        ->and(ownColumnFilter($project, $viewer, 'issue_id', FilterOperator::LessOrEqual, [$second->id]))->toBe([$first->id, $second->id])
        ->and(ownColumnFilter($project, $viewer, 'issue_id', FilterOperator::Between, [$second->id, $third->id]))->toBe([$second->id, $third->id]);
});

test('the parent filter matches children, descendants, and issues with or without a parent', function () {
    $project = Project::factory()->create();
    $viewer = ownColumnMember($project);
    $root = Issue::factory()->for($project)->create();
    $child = Issue::factory()->for($project)->create(['parent_id' => $root->id]);
    $grandchild = Issue::factory()->for($project)->create(['parent_id' => $child->id]);
    $unrelated = Issue::factory()->for($project)->create();

    expect(ownColumnFilter($project, $viewer, 'parent_id', FilterOperator::Equals, [$root->id]))->toBe([$child->id])
        ->and(ownColumnFilter($project, $viewer, 'parent_id', FilterOperator::Contains, [$root->id]))->toBe([$child->id, $grandchild->id])
        ->and(ownColumnFilter($project, $viewer, 'parent_id', FilterOperator::IsEmpty))->toBe([$root->id, $unrelated->id])
        ->and(ownColumnFilter($project, $viewer, 'parent_id', FilterOperator::IsNotEmpty))->toBe([$child->id, $grandchild->id]);
});

test('the new filters survive a saved query round trip on the issue list', function () {
    $project = Project::factory()->create();
    $user = ownColumnMember($project);
    $match = Issue::factory()->for($project)->create(['description' => 'needle here', 'estimated_hours' => 3]);
    Issue::factory()->for($project)->create(['description' => 'needle here', 'estimated_hours' => 10]);
    Issue::factory()->for($project)->create(['description' => 'haystack', 'estimated_hours' => 3]);
    Journal::create(['issue_id' => $match->id, 'user_id' => $user->id, 'notes' => 'confirmed']);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('addFilter', 'description')
        ->set('filterOperators.description', '~')
        ->set('filterValues.description.0', 'needle')
        ->call('addFilter', 'estimated_hours')
        ->set('filterOperators.estimated_hours', '<=')
        ->set('filterValues.estimated_hours.0', '5')
        ->call('addFilter', 'notes')
        ->set('filterOperators.notes', '~')
        ->set('filterValues.notes.0', 'confirmed')
        ->set('newQueryName', 'Own columns')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Own columns')->firstOrFail();

    expect(array_keys($saved->filters))->toBe(['description', 'estimated_hours', 'notes']);

    $issues = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('loadQuery', $saved->id)
        ->get('issues');

    expect($issues->pluck('id')->all())->toBe([$match->id]);
});

test('matching issues from another project or invisible issues never reach the list', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'default']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $visible = Issue::factory()->for($project)->create(['description' => 'needle']);
    $invisible = Issue::factory()->for($project)->create(['description' => 'needle', 'is_private' => true]);
    $elsewhere = Issue::factory()->for(Project::factory()->create())->create(['description' => 'needle']);

    foreach ([$visible, $invisible, $elsewhere] as $issue) {
        Journal::create(['issue_id' => $issue->id, 'user_id' => $issue->author_id, 'notes' => 'needle note']);
    }

    foreach ([['description', '~', 'needle'], ['notes', '~', 'needle'], ['issue_id', '=', "{$visible->id},{$invisible->id},{$elsewhere->id}"]] as [$key, $operator, $value]) {
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
