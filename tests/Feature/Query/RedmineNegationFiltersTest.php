<?php

use App\Enums\FilterOperator;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Support\Query\IssueFilterFieldRegistry;

/**
 * Redmine's negating operators keep the rows that have nothing set (query.rb sql_for_field and
 * sql_for_custom_field), which A17-05..07 found this app dropping.
 */
function negationProject(): array
{
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);

    return [$project, $tracker];
}

function negationIssue(Project $project, Tracker $tracker, array $attributes = []): Issue
{
    return Issue::factory()->for($project)->create([
        'tracker_id' => $tracker->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        ...$attributes,
    ]);
}

/**
 * @return array<int, int>
 */
function negationMatches(Project $project, string $key, FilterOperator $operator, array $values = []): array
{
    $field = IssueFilterFieldRegistry::forProject($project->fresh())->get($key);

    return $field->apply(Issue::query()->where('project_id', $project->id), $operator, $values)->orderBy('id')->pluck('id')->all();
}

test('"category is not A" keeps issues with no category (A17-05)', function () {
    [$project, $tracker] = negationProject();
    $a = IssueCategory::factory()->for($project)->create();
    $b = IssueCategory::factory()->for($project)->create();
    $inA = negationIssue($project, $tracker, ['category_id' => $a->id]);
    $inB = negationIssue($project, $tracker, ['category_id' => $b->id]);
    $none = negationIssue($project, $tracker);

    expect(negationMatches($project, 'category_id', FilterOperator::NotIn, [(string) $a->id]))->toBe([$inB->id, $none->id]);
});

test('custom field negations follow Redmine for multi-value fields and missing values (A17-06)', function () {
    [$project, $tracker] = negationProject();
    $field = CustomField::factory()->list(['A', 'B', 'C'])->multiple()->create();
    $field->trackers()->attach($tracker);
    $ab = negationIssue($project, $tracker);
    $ab->setCustomFieldValues([$field->id => ['A', 'B']]);
    $c = negationIssue($project, $tracker);
    $c->setCustomFieldValues([$field->id => ['C']]);
    $nothing = negationIssue($project, $tracker);

    // [A, B] is not "not A"; an issue with no value is.
    expect(negationMatches($project, "cf_{$field->id}", FilterOperator::NotIn, ['A']))->toBe([$c->id, $nothing->id])
        ->and(negationMatches($project, "cf_{$field->id}", FilterOperator::In, ['A']))->toBe([$ab->id])
        ->and(negationMatches($project, "cf_{$field->id}", FilterOperator::IsEmpty))->toBe([$nothing->id])
        ->and(negationMatches($project, "cf_{$field->id}", FilterOperator::IsNotEmpty))->toBe([$ab->id, $c->id]);
});

test('"does not contain" on a text custom field includes issues with no value (A17-06)', function () {
    [$project, $tracker] = negationProject();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $match = negationIssue($project, $tracker);
    $match->setCustomFieldValues([$field->id => 'checkout bug']);
    $other = negationIssue($project, $tracker);
    $other->setCustomFieldValues([$field->id => 'search']);
    $nothing = negationIssue($project, $tracker);

    expect(negationMatches($project, "cf_{$field->id}", FilterOperator::NotContains, ['checkout']))->toBe([$other->id, $nothing->id]);
});

test('the author filter offers "me" and resolves it to the signed-in user (A17-20)', function () {
    [$project, $tracker] = negationProject();
    $me = User::factory()->create();
    $mine = negationIssue($project, $tracker, ['author_id' => $me->id]);
    negationIssue($project, $tracker);
    $this->actingAs($me);

    $author = IssueFilterFieldRegistry::forProject($project->fresh(), $me)->get('author_id');

    expect($author->options())->toHaveKey('me')
        ->and(negationMatches($project, 'author_id', FilterOperator::In, ['me']))->toBe([$mine->id]);
});
