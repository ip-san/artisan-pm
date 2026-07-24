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
use Laravel\Passport\Passport;

function apiRelationMember(Project $project, array $permissions = ['view_issues', 'manage_issue_relations']): User
{
    $user = User::factory()->create();
    $role = Role::factory()->create(['permissions' => $permissions]);
    Member::factory()->for($project)->for($user)->create()->roles()->attach($role);

    return $user;
}

function apiRelationIssue(Project $project): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);
}

test('unauthenticated requests are rejected', function () {
    $project = Project::factory()->create();
    $issue = apiRelationIssue($project);

    $this->getJson("/api/v1/issues/{$issue->id}/relations")->assertUnauthorized();
});

test('a member with manage_issue_relations can create a relation to a visible issue', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);

    Passport::actingAs($user);

    $response = $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.issue_id', $issue->id)
        ->assertJsonPath('data.issue_to_id', $other->id)
        ->assertJsonPath('data.relation_type', 'relates');

    expect(IssueRelation::where('issue_from_id', $issue->id)->where('issue_to_id', $other->id)->exists())->toBeTrue();
});

test('a user without manage_issue_relations cannot create a relation', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project, ['view_issues']);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertForbidden();

    expect(IssueRelation::where('issue_from_id', $issue->id)->exists())->toBeFalse();
});

test('an issue cannot be related to itself', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $issue->id,
        'relation_type' => 'relates',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a duplicate relation is rejected', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a cross-project relation is rejected by default', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = apiRelationMember($projectA);
    Member::factory()->for($projectB)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues']])
    );
    $issue = apiRelationIssue($projectA);
    $other = apiRelationIssue($projectB);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a cross-project relation is allowed once the setting is enabled', function () {
    Setting::set('cross_project_issue_relations', true);
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = apiRelationMember($projectA);
    Member::factory()->for($projectB)->for($user)->create()->roles()->attach(
        Role::factory()->create(['permissions' => ['view_issues']])
    );
    $issue = apiRelationIssue($projectA);
    $other = apiRelationIssue($projectB);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertCreated();
});

test('relating a parent issue to its own child is rejected', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $parent = apiRelationIssue($project);
    $child = apiRelationIssue($project);
    $child->update(['parent_id' => $parent->id]);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$parent->id}/relations", [
        'issue_to_id' => $child->id,
        'relation_type' => 'relates',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a reverse "relates" relation is rejected as a duplicate', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $other->id, 'issue_to_id' => $issue->id, 'relation_type' => 'relates']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a circular direct blocks relation is rejected', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $other->id, 'issue_to_id' => $issue->id, 'relation_type' => 'blocks']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'blocks',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);
});

test('a chained circular precedes relation across three issues is rejected', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $a = apiRelationIssue($project);
    $b = apiRelationIssue($project);
    $c = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $b->id, 'issue_to_id' => $c->id, 'relation_type' => 'precedes']);

    Passport::actingAs($user);

    // C already (transitively) follows A via B, so C precedes A would close the loop.
    $this->postJson("/api/v1/issues/{$c->id}/relations", [
        'issue_to_id' => $a->id,
        'relation_type' => 'precedes',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);

    expect(IssueRelation::count())->toBe(2);
});

test('a chained circular follows relation across three issues is rejected', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $a = apiRelationIssue($project);
    $b = apiRelationIssue($project);
    $c = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);
    IssueRelation::create(['issue_from_id' => $b->id, 'issue_to_id' => $c->id, 'relation_type' => 'precedes']);

    Passport::actingAs($user);

    // A already precedes C via B, so A follows C would close the loop.
    $this->postJson("/api/v1/issues/{$a->id}/relations", [
        'issue_to_id' => $c->id,
        'relation_type' => 'follows',
    ])->assertUnprocessable()->assertJsonValidationErrors(['issue_to_id']);

    expect(IssueRelation::count())->toBe(2);
});

test('a non-circular precedes relation extending an existing chain is accepted', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $a = apiRelationIssue($project);
    $b = apiRelationIssue($project);
    $c = apiRelationIssue($project);
    IssueRelation::create(['issue_from_id' => $a->id, 'issue_to_id' => $b->id, 'relation_type' => 'precedes']);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$b->id}/relations", [
        'issue_to_id' => $c->id,
        'relation_type' => 'precedes',
    ])->assertCreated();

    expect(IssueRelation::count())->toBe(2);
});

test('a delay is recorded on a precedes relation but discarded for other types', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $precedesTarget = apiRelationIssue($project);
    $relatesTarget = apiRelationIssue($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $precedesTarget->id,
        'relation_type' => 'precedes',
        'delay' => 3,
    ])->assertCreated()->assertJsonPath('data.delay', 3);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $relatesTarget->id,
        'relation_type' => 'relates',
        'delay' => 3,
    ])->assertCreated()->assertJsonPath('data.delay', null);
});

test('a copied_to relation cannot be manually created through the api', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'copied_to',
    ])->assertUnprocessable()->assertJsonValidationErrors(['relation_type']);
});

test('listing relations only shows the ones the requester can view, from both directions', function () {
    $project = Project::factory()->create();
    $privateProject = Project::factory()->private()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $visibleFrom = apiRelationIssue($project);
    $visibleTo = apiRelationIssue($project);
    $hiddenIssue = apiRelationIssue($privateProject);

    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $visibleTo->id, 'relation_type' => 'relates']);
    IssueRelation::create(['issue_from_id' => $visibleFrom->id, 'issue_to_id' => $issue->id, 'relation_type' => 'relates']);
    IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $hiddenIssue->id, 'relation_type' => 'relates']);

    Passport::actingAs($user);

    $response = $this->getJson("/api/v1/issues/{$issue->id}/relations")->assertOk();

    $otherIssueIds = collect($response->json('data'))
        ->map(fn (array $relation) => $relation['issue_id'] === $issue->id ? $relation['issue_to_id'] : $relation['issue_id']);

    expect($otherIssueIds)->toHaveCount(2)
        ->and($otherIssueIds)->toContain($visibleTo->id, $visibleFrom->id)
        ->not->toContain($hiddenIssue->id);
});

test('a member with manage_issue_relations can delete a relation from either side', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);
    $relation = IssueRelation::create(['issue_from_id' => $other->id, 'issue_to_id' => $issue->id, 'relation_type' => 'relates']);

    Passport::actingAs($user);

    $this->deleteJson("/api/v1/relations/{$relation->id}")->assertNoContent();

    expect(IssueRelation::find($relation->id))->toBeNull();
});

test('a user without manage_issue_relations on either side cannot delete a relation', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $issue = apiRelationIssue($projectA);
    $other = apiRelationIssue($projectB);
    $relation = IssueRelation::create(['issue_from_id' => $issue->id, 'issue_to_id' => $other->id, 'relation_type' => 'relates']);
    $outsider = User::factory()->create();

    Passport::actingAs($outsider);

    $this->deleteJson("/api/v1/relations/{$relation->id}")->assertForbidden();

    expect(IssueRelation::find($relation->id))->not->toBeNull();
});

test('adding a relation journals both issues', function () {
    $project = Project::factory()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    $other = apiRelationIssue($project);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertCreated();

    expect($issue->journals()->whereHas('details', fn ($q) => $q->where('property', 'relation'))->exists())->toBeTrue()
        ->and($other->journals()->whereHas('details', fn ($q) => $q->where('property', 'relation'))->exists())->toBeTrue();
});

test('a relation cannot be created to an issue in a project the requester cannot view, even with cross_project_issue_relations enabled', function () {
    Setting::set('cross_project_issue_relations', true);
    $project = Project::factory()->create();
    $hiddenProject = Project::factory()->private()->create();
    $user = apiRelationMember($project);
    $issue = apiRelationIssue($project);
    // Deliberately NOT a member of $hiddenProject — this issue is
    // invisible to $user, but manage_issue_relations on $issue's own
    // project alone must not be enough to relate to it.
    $other = apiRelationIssue($hiddenProject);

    Passport::actingAs($user);

    $this->postJson("/api/v1/issues/{$issue->id}/relations", [
        'issue_to_id' => $other->id,
        'relation_type' => 'relates',
    ])->assertForbidden();

    expect(IssueRelation::where('issue_from_id', $issue->id)->exists())->toBeFalse();
});
