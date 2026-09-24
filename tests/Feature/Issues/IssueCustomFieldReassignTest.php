<?php

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Redmine's reassign_custom_field_values (A1-51): a project or tracker
 * change deletes the values of the custom fields the issue no longer uses.
 */
function reassignValueCount(Issue $issue, CustomField $field): int
{
    return CustomFieldValue::query()
        ->where('customized_type', $issue->getMorphClass())
        ->where('customized_id', $issue->id)
        ->where('custom_field_id', $field->id)
        ->count();
}

test('moving an issue deletes the values of custom fields the target project does not use, keeping the shared ones', function () {
    $tracker = Tracker::factory()->create();
    $source = Project::factory()->create();
    $target = Project::factory()->create();
    $source->trackers()->attach($tracker);
    $target->trackers()->attach($tracker);

    $sourceOnly = CustomField::factory()->create(['name' => 'Source only']);
    $sourceOnly->trackers()->attach($tracker);
    $sourceOnly->projects()->attach($source);
    $everywhere = CustomField::factory()->create(['name' => 'Everywhere']);
    $everywhere->trackers()->attach($tracker);

    $issue = Issue::factory()->for($source)->create(['tracker_id' => $tracker->id]);
    $child = Issue::factory()->for($source)->create(['tracker_id' => $tracker->id, 'parent_id' => $issue->id]);
    $issue->setCustomFieldValues([$sourceOnly->id => 'gone', $everywhere->id => 'kept'], collect([$sourceOnly, $everywhere]));
    $child->setCustomFieldValues([$sourceOnly->id => 'child gone'], collect([$sourceOnly]));

    app(IssueService::class)->moveToProject($issue, $target, $tracker->id, User::factory()->admin()->create());

    expect(reassignValueCount($issue, $sourceOnly))->toBe(0)
        ->and(reassignValueCount($child, $sourceOnly))->toBe(0)
        ->and($issue->fresh()->load('customFieldValues')->customValue($everywhere))->toBe('kept');

    // Moving back does not bring the value back.
    app(IssueService::class)->moveToProject($issue->fresh(), $source, $tracker->id, User::factory()->admin()->create());

    expect(reassignValueCount($issue, $sourceOnly))->toBe(0);
});

test('changing the tracker deletes the values of custom fields the new tracker does not use', function () {
    $project = Project::factory()->create();
    $bug = Tracker::factory()->create();
    $feature = Tracker::factory()->create();
    $project->trackers()->attach([$bug->id, $feature->id]);
    $bugOnly = CustomField::factory()->create();
    $bugOnly->trackers()->attach($bug);
    $both = CustomField::factory()->create();
    $both->trackers()->attach([$bug->id, $feature->id]);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $bug->id]);
    $issue->setCustomFieldValues([$bugOnly->id => 'bug value', $both->id => 'shared'], collect([$bugOnly, $both]));

    app(IssueService::class)->update($issue, ['tracker_id' => $feature->id], User::factory()->admin()->create());

    expect(reassignValueCount($issue, $bugOnly))->toBe(0)
        ->and(reassignValueCount($issue, $both))->toBe(1);
});

test('an update that keeps the project and tracker leaves stale values alone', function () {
    $project = Project::factory()->create();
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    $unlinked = CustomField::factory()->create();

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    CustomFieldValue::query()->create([
        'custom_field_id' => $unlinked->id, 'customized_type' => $issue->getMorphClass(), 'customized_id' => $issue->id, 'value' => 'x',
    ]);

    app(IssueService::class)->update($issue, ['subject' => 'Renamed'], User::factory()->admin()->create());

    expect(reassignValueCount($issue, $unlinked))->toBe(1);
});

test('the file of an attachment field the new tracker does not use is deleted too', function () {
    $project = Project::factory()->create();
    $withField = Tracker::factory()->create();
    $without = Tracker::factory()->create();
    $project->trackers()->attach([$withField->id, $without->id]);
    $field = CustomField::factory()->create(['field_format' => CustomFieldFormat::Attachment]);
    $field->trackers()->attach($withField);
    $admin = User::factory()->admin()->create();

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $withField->id]);
    app(IssueService::class)->update($issue, [], $admin, customFieldData: [$field->id => UploadedFile::fake()->create('spec.pdf', 10)]);

    expect(Media::query()->where('collection_name', AttachmentFormat::COLLECTION)->count())->toBe(1);

    app(IssueService::class)->update($issue->fresh(), ['tracker_id' => $without->id], $admin);

    expect(Media::query()->where('collection_name', AttachmentFormat::COLLECTION)->count())->toBe(0)
        ->and(reassignValueCount($issue, $field))->toBe(0);
});
