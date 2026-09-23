<?php

use App\Enums\FilterOperator;
use App\Enums\IssueRelationType;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Query as SavedQuery;
use App\Models\Role;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;
use App\Support\Query\QueryFilterEngine;
use Livewire\Livewire;

/**
 * A1-17b: child_id and the per-relation-type filters.
 *
 * @param  array<int, string>  $permissions
 */
function relationFilterMember(Project $project, array $permissions = ['view_issues', 'save_queries']): User
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
function relationFilter(Project $project, User $viewer, string $key, FilterOperator $operator, array $values = []): array
{
    $engine = new QueryFilterEngine(IssueFilterFieldRegistry::forProject($project, $viewer));

    return $engine->applyFilters(Issue::query()->where('project_id', $project->id), [
        $key => ['operator' => $operator->value, 'values' => $values],
    ])->orderBy('id')->pluck('id')->all();
}

function relate(Issue $from, Issue $to, IssueRelationType $type): void
{
    IssueRelation::factory()->create(['issue_from_id' => $from->id, 'issue_to_id' => $to->id, 'relation_type' => $type->value]);
}

test('every Redmine relation name is offered as a filter', function () {
    $project = Project::factory()->create();

    expect(IssueFilterFieldRegistry::forProject($project, relationFilterMember($project))->keys()->all())
        ->toContain('relates', 'duplicates', 'duplicated', 'blocks', 'blocked', 'precedes', 'follows', 'copied_to', 'copied_from', 'child_id');
});

test('blocks and blocked read the two ends of a blocks relation', function () {
    $project = Project::factory()->create();
    $viewer = relationFilterMember($project);
    [$blocker, $blocked, $loose] = Issue::factory()->for($project)->count(3)->create()->all();
    relate($blocker, $blocked, IssueRelationType::Blocks);

    expect(relationFilter($project, $viewer, 'blocks', FilterOperator::IsNotEmpty))->toBe([$blocker->id])
        ->and(relationFilter($project, $viewer, 'blocked', FilterOperator::IsNotEmpty))->toBe([$blocked->id])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::IsEmpty))->toBe([$blocked->id, $loose->id])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::Equals, [(string) $blocked->id]))->toBe([$blocker->id])
        ->and(relationFilter($project, $viewer, 'blocked', FilterOperator::Equals, [(string) $blocker->id]))->toBe([$blocked->id])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::NotEquals, [(string) $blocked->id]))->toBe([$blocked->id, $loose->id])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::Equals, ['x']))->toBe([]);
});

test('relates matches both ends and copied_from is the reverse of copied_to', function () {
    $project = Project::factory()->create();
    $viewer = relationFilterMember($project);
    [$first, $second, $original, $copy] = Issue::factory()->for($project)->count(4)->create()->all();
    relate($first, $second, IssueRelationType::Relates);
    relate($original, $copy, IssueRelationType::CopiedTo);

    expect(relationFilter($project, $viewer, 'relates', FilterOperator::IsNotEmpty))->toBe([$first->id, $second->id])
        ->and(relationFilter($project, $viewer, 'relates', FilterOperator::Equals, [(string) $first->id]))->toBe([$second->id])
        ->and(relationFilter($project, $viewer, 'copied_to', FilterOperator::IsNotEmpty))->toBe([$original->id])
        ->and(relationFilter($project, $viewer, 'copied_from', FilterOperator::Equals, [(string) $original->id]))->toBe([$copy->id]);
});

test('precedes and follows read both stored forms', function () {
    $project = Project::factory()->create();
    $viewer = relationFilterMember($project);
    [$a, $b, $c, $d] = Issue::factory()->for($project)->count(4)->create()->all();
    relate($a, $b, IssueRelationType::Precedes);
    relate($d, $c, IssueRelationType::Follows);

    expect(relationFilter($project, $viewer, 'precedes', FilterOperator::IsNotEmpty))->toBe([$a->id, $c->id])
        ->and(relationFilter($project, $viewer, 'follows', FilterOperator::IsNotEmpty))->toBe([$b->id, $d->id])
        ->and(relationFilter($project, $viewer, 'follows', FilterOperator::Equals, ["{$a->id},{$c->id}"]))->toBe([$b->id, $d->id]);
});

test('relation filters can ask for open related issues', function () {
    $project = Project::factory()->create();
    $viewer = relationFilterMember($project);
    $closed = IssueStatus::factory()->closed()->create();
    [$toOpen, $toClosed, $open] = Issue::factory()->for($project)->count(3)->create()->all();
    $closedIssue = Issue::factory()->for($project)->create(['status_id' => $closed->id]);
    relate($toOpen, $open, IssueRelationType::Blocks);
    relate($toClosed, $closedIssue, IssueRelationType::Blocks);

    expect(relationFilter($project, $viewer, 'blocks', FilterOperator::AnyOpenIssues))->toBe([$toOpen->id])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::NoOpenIssues))->toBe([$toClosed->id, $open->id, $closedIssue->id]);
});

test('relation filters can ask for related issues in a project the viewer can see', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    $hidden = Project::factory()->private()->create();
    $viewer = relationFilterMember($project);
    Member::factory()->for($other)->for($viewer)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));
    [$toOther, $toHidden, $toSame, $same] = Issue::factory()->for($project)->count(4)->create()->all();
    relate($toOther, Issue::factory()->for($other)->create(), IssueRelationType::Relates);
    relate($toHidden, Issue::factory()->for($hidden)->create(), IssueRelationType::Relates);
    relate($toSame, $same, IssueRelationType::Relates);

    expect(relationFilter($project, $viewer, 'relates', FilterOperator::AnyIssuesInProject, [(string) $other->id]))->toBe([$toOther->id])
        // A1-27b: a relation to an issue the viewer can't see never counts.
        ->and(relationFilter($project, $viewer, 'relates', FilterOperator::AnyIssuesNotInProject, [(string) $project->id]))->toBe([$toOther->id])
        ->and(relationFilter($project, $viewer, 'relates', FilterOperator::NoIssuesInProject, [(string) $project->id]))->toBe([$toOther->id, $toHidden->id])
        ->and(relationFilter($project, $viewer, 'relates', FilterOperator::AnyIssuesInProject, [(string) $hidden->id]))->toBe([])
        ->and(array_keys(IssueFilterFieldRegistry::forProject($project, $viewer)->get('relates')->options()))->not->toContain($hidden->id);
});

test('the subtask filter matches parents, ancestors, and issues with or without subtasks', function () {
    $project = Project::factory()->create();
    $viewer = relationFilterMember($project);
    $root = Issue::factory()->for($project)->create();
    $child = Issue::factory()->for($project)->create(['parent_id' => $root->id]);
    $grandchild = Issue::factory()->for($project)->create(['parent_id' => $child->id]);
    $leaf = Issue::factory()->for($project)->create();

    expect(relationFilter($project, $viewer, 'child_id', FilterOperator::Equals, [(string) $grandchild->id]))->toBe([$child->id])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::Contains, [(string) $grandchild->id]))->toBe([$root->id, $child->id])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::IsNotEmpty))->toBe([$root->id, $child->id])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::IsEmpty))->toBe([$grandchild->id, $leaf->id]);
});

test('a relation filter survives a saved query round trip and renders its project choice', function () {
    $project = Project::factory()->create(['name' => 'Relation Target']);
    $user = relationFilterMember($project);
    [$blocker, $blocked] = Issue::factory()->for($project)->count(2)->create()->all();
    relate($blocker, $blocked, IssueRelationType::Blocks);

    Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->call('addFilter', 'blocks')
        ->set('filterOperators.blocks', '=p')
        ->assertSeeHtml('<option value="'.$project->id.'">Relation Target</option>')
        ->set('filterOperators.blocks', '*o')
        ->set('newQueryName', 'Blocking open')
        ->call('saveQuery');

    $saved = SavedQuery::where('name', 'Blocking open')->firstOrFail();

    expect($saved->filters['blocks']['operator'])->toBe('*o');

    $issues = Livewire::actingAs($user)
        ->test('issues.index', ['project' => $project])
        ->set('statusFilter', 'all')
        ->call('loadQuery', $saved->id)
        ->get('issues');

    expect($issues->pluck('id')->all())->toBe([$blocker->id]);
});

test('matching issues from another project or invisible issues never reach the list', function () {
    $project = Project::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues'], 'issues_visibility' => 'default']);
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    $target = Issue::factory()->for($project)->create();
    $visible = Issue::factory()->for($project)->create();
    $invisible = Issue::factory()->for($project)->create(['is_private' => true]);
    $elsewhere = Issue::factory()->for(Project::factory()->create())->create();

    foreach ([$visible, $invisible, $elsewhere] as $issue) {
        relate($issue, $target, IssueRelationType::Blocks);
    }

    foreach ([['blocks', '*', null], ['blocks', '=', (string) $target->id], ['blocks', '*o', null]] as [$key, $operator, $value]) {
        $issues = Livewire::actingAs($user)
            ->test('issues.index', ['project' => $project])
            ->set('statusFilter', 'all')
            ->call('addFilter', $key)
            ->set("filterOperators.{$key}", $operator === '*' ? 'not_empty' : $operator)
            ->set("filterValues.{$key}", $value === null ? [] : [$value])
            ->get('issues');

        expect($issues->pluck('id')->all())->toBe([$visible->id]);
    }
});

test('relation and subtask filters ignore issues the viewer cannot see (A1-27b)', function () {
    $project = Project::factory()->create();
    $hidden = Project::factory()->private()->create();
    $viewer = relationFilterMember($project);
    [$blocker, $parent] = Issue::factory()->for($project)->count(2)->create()->all();
    $hiddenIssue = Issue::factory()->for($hidden)->create();
    relate($blocker, $hiddenIssue, IssueRelationType::Blocks);
    $hiddenIssue->update(['parent_id' => $parent->id]);

    expect(relationFilter($project, $viewer, 'blocks', FilterOperator::IsNotEmpty))->toBe([])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::Equals, [(string) $hiddenIssue->id]))->toBe([])
        ->and(relationFilter($project, $viewer, 'blocks', FilterOperator::IsEmpty))->toBe([$blocker->id, $parent->id])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::IsNotEmpty))->toBe([])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::Equals, [(string) $hiddenIssue->id]))->toBe([])
        ->and(relationFilter($project, $viewer, 'child_id', FilterOperator::Contains, [(string) $hiddenIssue->id]))->toBe([]);
});
