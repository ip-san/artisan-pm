<?php

use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tracker;
use App\Models\User;
use Livewire\Livewire;

function relationProjectMember(Project $project, array $permissions = ['view_issues', 'manage_issue_relations']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    $member = Member::factory()->for($project)->for($user)->create();
    $member->roles()->attach($role);

    return $user;
}

function makeIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('a member with manage_issue_relations can add a relation to a visible issue', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'blocks')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasNoErrors();

    $relation = IssueRelation::where('issue_from_id', $issue->id)->where('issue_to_id', $other->id)->firstOrFail();
    expect($relation->relation_type->value)->toBe('blocks');
});

test('a user without manage_issue_relations cannot add a relation', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project, ['view_issues']);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertForbidden();

    expect(IssueRelation::count())->toBe(0);
});

test('an issue cannot be related to itself', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relatedIssueId', $issue->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);
});

test('a duplicate relation is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'relates')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);
});

test('a relation cannot be created to an issue the user cannot view', function () {
    // Cross-project relations are allowed here specifically so this
    // exercises the authorize('view') check rather than being rejected
    // earlier by the cross-project restriction (covered separately below).
    Setting::set('cross_project_issue_relations', true);

    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $foreignIssue = makeIssue($otherProject);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relatedIssueId', $foreignIssue->id)
        ->call('addRelation')
        ->assertForbidden();

    expect(IssueRelation::count())->toBe(0);
});

test('relations are shown from both directions with the correct reverse label', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $blocker = makeIssue($project);
    $blocked = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $blocker->id, 'issue_to_id' => $blocked->id, 'relation_type' => 'blocks']);

    $fromBlockerSide = Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $blocker]);
    $fromBlockedSide = Livewire::actingAs($user)->test('issues.show', ['project' => $project, 'issue' => $blocked]);

    expect($fromBlockerSide->instance()->relations->first()['label'])->toBe('ブロックする')
        ->and($fromBlockedSide->instance()->relations->first()['label'])->toBe('ブロックされている');
});

test('a member with manage_issue_relations can delete a relation from either side', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    $relation = IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $other])
        ->call('deleteRelation', $relation->id);

    expect(IssueRelation::find($relation->id))->toBeNull();
});

test('a delay in days can be recorded on a precedes relation', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'precedes')
        ->set('relatedIssueId', $other->id)
        ->set('relationDelay', 3)
        ->call('addRelation')
        ->assertHasNoErrors();

    $relation = IssueRelation::where('issue_from_id', $issue->id)->where('issue_to_id', $other->id)->firstOrFail();
    expect($relation->delay)->toBe(3);
});

test('delay is discarded for relation types other than precedes/follows', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'blocks')
        ->set('relatedIssueId', $other->id)
        ->set('relationDelay', 5)
        ->call('addRelation')
        ->assertHasNoErrors();

    $relation = IssueRelation::where('issue_from_id', $issue->id)->where('issue_to_id', $other->id)->firstOrFail();
    expect($relation->delay)->toBeNull();
});

test('the relation list shows the delay for a precedes relation', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    IssueRelation::create([
        'issue_from_id' => $issue->id, 'issue_to_id' => $other->id,
        'relation_type' => 'precedes', 'delay' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->assertSee('2日後');
});

test('a cross-project relation is rejected by default', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($otherProject);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'relates')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(0);
});

test('a cross-project relation is allowed once the setting is enabled', function () {
    Setting::set('cross_project_issue_relations', true);

    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($otherProject);

    $member = Member::factory()->for($otherProject)->for($user)->create();
    $member->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'relates')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasNoErrors();

    expect(IssueRelation::count())->toBe(1);
});

test('relating a parent issue to its own child is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $parent = makeIssue($project);
    $child = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'parent_id' => $parent->id,
    ]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $parent])
        ->set('relationType', 'relates')
        ->set('relatedIssueId', $child->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(0);
});

test('a reverse "relates" relation is rejected as a duplicate', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $other->id, 'issue_to_id' => $issue->id, 'relation_type' => 'relates']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'relates')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(1);
});

test('a circular direct blocks relation is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $other->id, 'issue_to_id' => $issue->id, 'relation_type' => 'blocks']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'blocks')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(1);
});

test('a direct circular precedes relation is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $other->id, 'relation_type' => 'precedes']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $other])
        ->set('relationType', 'precedes')
        ->set('relatedIssueId', $issue->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(1);
});

test('a chained circular precedes relation across three issues is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $a = makeIssue($project);
    $b = makeIssue($project);
    $c = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $b->id, 'issue_to_id' => $c->id, 'relation_type' => 'precedes']);

    // C already (transitively) follows A via B, so C precedes A would close the loop.
    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $c])
        ->set('relationType', 'precedes')
        ->set('relatedIssueId', $a->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(2);
});

test('a chained circular follows relation across three issues is rejected', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $a = makeIssue($project);
    $b = makeIssue($project);
    $c = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $b->id, 'issue_to_id' => $c->id, 'relation_type' => 'precedes']);

    // A already precedes C via B, so A follows C would close the loop.
    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $a])
        ->set('relationType', 'follows')
        ->set('relatedIssueId', $c->id)
        ->call('addRelation')
        ->assertHasErrors(['relatedIssueId']);

    expect(IssueRelation::count())->toBe(2);
});

test('a non-circular precedes relation extending an existing chain is accepted', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $a = makeIssue($project);
    $b = makeIssue($project);
    $c = makeIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $b])
        ->set('relationType', 'precedes')
        ->set('relatedIssueId', $c->id)
        ->call('addRelation')
        ->assertHasNoErrors();

    expect(IssueRelation::count())->toBe(2);
});

test('adding a relation journals both issues with the type as seen from each side', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'blocks')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasNoErrors();

    $fromDetail = $issue->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($fromDetail->property)->toBe('relation')
        ->and($fromDetail->prop_key)->toBe('blocks')
        ->and($fromDetail->new_value)->toBe((string) $other->id)
        ->and($fromDetail->old_value)->toBeNull();

    $toDetail = $other->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($toDetail->prop_key)->toBe('blocked')
        ->and($toDetail->new_value)->toBe((string) $issue->id);
});

test('removing a relation journals both issues with the old value set', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);
    $relation = IssueRelation::create([
        'issue_from_id' => $issue->id,
        'issue_to_id' => $other->id,
        'relation_type' => 'precedes',
    ]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->call('deleteRelation', $relation->id);

    $fromDetail = $issue->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($fromDetail->property)->toBe('relation')
        ->and($fromDetail->prop_key)->toBe('precedes')
        ->and($fromDetail->old_value)->toBe((string) $other->id)
        ->and($fromDetail->new_value)->toBeNull();

    $toDetail = $other->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($toDetail->prop_key)->toBe('follows')
        ->and($toDetail->old_value)->toBe((string) $issue->id);
});

test('a copied_to relation cannot be manually created through the relation form', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $issue = makeIssue($project);
    $other = makeIssue($project);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $issue])
        ->set('relationType', 'copied_to')
        ->set('relatedIssueId', $other->id)
        ->call('addRelation')
        ->assertHasErrors(['relationType']);

    expect(IssueRelation::query()->where('issue_from_id', $issue->id)->exists())->toBeFalse();
});

test('a copied_to relation shows "コピー先" from the source and "コピー元" from the copy', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $source = makeIssue($project);
    $copy = makeIssue($project);
    IssueRelation::create([
        'issue_from_id' => $source->id,
        'issue_to_id' => $copy->id,
        'relation_type' => 'copied_to',
    ]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $source])
        ->assertSee('コピー先');

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $copy])
        ->assertSee('コピー元');
});

test('deleting a copied_to relation journals "copied_to" on the source and "copied_from" on the copy', function () {
    $project = Project::factory()->create();
    $user = relationProjectMember($project);
    $source = makeIssue($project);
    $copy = makeIssue($project);
    $relation = IssueRelation::create([
        'issue_from_id' => $source->id,
        'issue_to_id' => $copy->id,
        'relation_type' => 'copied_to',
    ]);

    Livewire::actingAs($user)
        ->test('issues.show', ['project' => $project, 'issue' => $source])
        ->call('deleteRelation', $relation->id);

    $fromDetail = $source->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($fromDetail->prop_key)->toBe('copied_to');

    $toDetail = $copy->journals()->latest('id')->firstOrFail()->details()->firstOrFail();
    expect($toDetail->prop_key)->toBe('copied_from');
});
