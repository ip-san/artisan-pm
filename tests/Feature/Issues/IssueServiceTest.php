<?php

use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;

function issueService(): IssueService
{
    return app(IssueService::class);
}

test('creating an issue does not write a journal entry', function () {
    $author = User::factory()->create();
    $project = Project::factory()->create();

    $issue = issueService()->create([
        'project_id' => $project->id,
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
        'subject' => 'First issue',
    ], $author);

    expect($issue->author_id)->toBe($author->id)
        ->and($issue->journals)->toBeEmpty();
});

test('updating journaled fields records a journal entry with the diff', function () {
    $actor = User::factory()->create();
    $issue = Issue::factory()->create(['subject' => 'Old subject']);

    $updated = issueService()->update($issue, ['subject' => 'New subject'], $actor, 'Renamed it');

    $journal = $updated->journals()->firstOrFail();

    expect($journal->notes)->toBe('Renamed it')
        ->and($journal->user_id)->toBe($actor->id)
        ->and($journal->details)->toHaveCount(1)
        ->and($journal->details->first()->prop_key)->toBe('subject')
        ->and($journal->details->first()->old_value)->toBe('Old subject')
        ->and($journal->details->first()->new_value)->toBe('New subject');
});

test('updating to a closed status stamps closed_on, and reopening clears it', function () {
    $actor = User::factory()->create();
    $open = IssueStatus::factory()->create(['is_closed' => false]);
    $closed = IssueStatus::factory()->closed()->create();

    $issue = Issue::factory()->create(['status_id' => $open->id]);

    $issue = issueService()->update($issue, ['status_id' => $closed->id], $actor);
    expect($issue->closed_on)->not->toBeNull();

    $issue = issueService()->update($issue, ['status_id' => $open->id], $actor);
    expect($issue->closed_on)->toBeNull();
});

test('an update with no field changes and no comment writes no journal', function () {
    $actor = User::factory()->create();
    $issue = Issue::factory()->create(['subject' => 'Same subject']);

    issueService()->update($issue, ['subject' => 'Same subject'], $actor);

    expect($issue->fresh()->journals)->toBeEmpty();
});

test('a custom field change is recorded in the journal', function () {
    $actor = User::factory()->create();
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create(['name' => 'Severity']);
    $field->trackers()->attach($tracker);
    $issue = Issue::factory()->create(['tracker_id' => $tracker->id]);

    $updated = issueService()->update($issue, [], $actor, null, [$field->id => 'High']);

    $journal = $updated->journals()->firstOrFail();
    $detail = $journal->details->firstOrFail();

    expect($detail->property)->toBe('cf')
        ->and($detail->prop_key)->toBe((string) $field->id)
        ->and($detail->old_value)->toBeNull()
        ->and($detail->new_value)->toBe('High');
});

test('a custom field change alongside a core attribute change is journaled in one entry', function () {
    $actor = User::factory()->create();
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $issue = Issue::factory()->create(['tracker_id' => $tracker->id, 'subject' => 'Old subject']);
    $issue->setCustomFieldValues([$field->id => 'Low']);

    $updated = issueService()->update($issue, ['subject' => 'New subject'], $actor, null, [$field->id => 'High']);

    $journal = $updated->journals()->firstOrFail();

    expect($journal->details)->toHaveCount(2)
        ->and($journal->details->firstWhere('property', 'attr')->prop_key)->toBe('subject')
        ->and($journal->details->firstWhere('property', 'cf')->old_value)->toBe('Low')
        ->and($journal->details->firstWhere('property', 'cf')->new_value)->toBe('High');
});

test('an unchanged custom field value writes no journal entry for it', function () {
    $actor = User::factory()->create();
    $tracker = Tracker::factory()->create();
    $field = CustomField::factory()->create();
    $field->trackers()->attach($tracker);
    $issue = Issue::factory()->create(['tracker_id' => $tracker->id]);
    $issue->setCustomFieldValues([$field->id => 'Same']);

    $updated = issueService()->update($issue, [], $actor, null, [$field->id => 'Same']);

    expect($updated->journals)->toBeEmpty();
});
