<?php

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @return array{project: Project, tracker: Tracker, field: CustomField, issue: Issue, member: User}
 */
function attachmentFieldSetup(array $fieldAttributes = []): array
{
    $project = Project::factory()->create(['is_public' => false]);
    $tracker = Tracker::factory()->create();
    $project->trackers()->attach($tracker);
    IssueStatus::factory()->create();
    Enumeration::factory()->create(['is_default' => true]);

    $field = CustomField::factory()->create(['name' => 'Spec sheet', 'field_format' => CustomFieldFormat::Attachment, ...$fieldAttributes]);
    $field->trackers()->attach($tracker);

    $member = User::factory()->create();
    $role = Role::factory()->create(['permissions' => ['view_issues', 'add_issues', 'edit_issues']]);
    Member::factory()->for($project)->for($member)->create()->roles()->attach($role);

    $issue = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);

    return compact('project', 'tracker', 'field', 'issue', 'member');
}

function attachFieldFile(Issue $issue, CustomField $field, string $name = 'spec.pdf', ?User $actor = null): Media
{
    app(IssueService::class)->update($issue->fresh(), [], $actor ?? User::factory()->admin()->create(), customFieldData: [$field->id => UploadedFile::fake()->create($name, 10)]);

    return Media::query()->where('collection_name', AttachmentFormat::COLLECTION)->latest('id')->firstOrFail();
}

test('a file uploaded in the issue form becomes the field value, shown as a download link', function () {
    ['project' => $project, 'field' => $field, 'issue' => $issue, 'member' => $member] = attachmentFieldSetup();

    Livewire::actingAs($member)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('spec.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    $media = $issue->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();

    expect($media->file_name)->toBe('spec.pdf')
        ->and($media->getCustomProperty('custom_field_id'))->toBe($field->id)
        ->and($issue->fresh()->load('customFieldValues')->customValue($field))->toBe($media->id)
        ->and($issue->fresh()->getMedia('attachments'))->toHaveCount(0);

    $this->actingAs($member)->get(route('issues.show', [$project, $issue]))
        ->assertOk()
        ->assertSee(route('attachments.show', $media), false)
        ->assertSee('spec.pdf')
        ->assertSee('Spec sheetが更新されました')
        ->assertDontSee('Spec sheet: ');

    $this->actingAs($member)->get(route('attachments.show', $media))->assertOk();

    $detail = JournalDetail::query()->where('property', 'cf')->where('prop_key', (string) $field->id)->sole();
    expect($detail->new_value)->toBe((string) $media->id);
});

test('replacing or clearing the value deletes the previous file', function () {
    ['field' => $field, 'issue' => $issue] = attachmentFieldSetup();

    $first = attachFieldFile($issue, $field, 'first.pdf');
    $firstPath = $first->getPath();
    $second = attachFieldFile($issue, $field, 'second.pdf');

    expect(Media::query()->find($first->id))->toBeNull()
        ->and(file_exists($firstPath))->toBeFalse()
        ->and($issue->fresh()->load('customFieldValues')->customValue($field))->toBe($second->id);

    app(IssueService::class)->update($issue->fresh(), [], User::factory()->admin()->create(), customFieldData: [$field->id => '']);

    expect(Media::query()->find($second->id))->toBeNull()
        ->and($issue->fresh()->load('customFieldValues')->customValue($field))->toBeNull();
});

test('sending the current id keeps the file and another record\'s file id is never taken over', function () {
    ['field' => $field, 'issue' => $issue, 'project' => $project, 'tracker' => $tracker] = attachmentFieldSetup();
    $own = attachFieldFile($issue, $field, 'own.pdf');

    $other = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $foreign = attachFieldFile($other, $field, 'foreign.pdf');

    app(IssueService::class)->update($issue->fresh(), [], User::factory()->admin()->create(), customFieldData: [$field->id => (string) $own->id]);
    app(IssueService::class)->update($issue->fresh(), [], User::factory()->admin()->create(), customFieldData: [$field->id => (string) $foreign->id]);

    expect($issue->fresh()->load('customFieldValues')->customValue($field))->toBe($own->id)
        ->and(Media::query()->find($foreign->id)?->model_id)->toBe($other->id);
});

test('deleting the issue or the field deletes the file', function () {
    ['field' => $field, 'issue' => $issue, 'project' => $project, 'tracker' => $tracker] = attachmentFieldSetup();
    $media = attachFieldFile($issue, $field);

    app(IssueService::class)->delete($issue->fresh());
    expect(Media::query()->find($media->id))->toBeNull();

    $another = Issue::factory()->for($project)->create(['tracker_id' => $tracker->id]);
    $media = attachFieldFile($another, $field);
    $field->delete();
    expect(Media::query()->find($media->id))->toBeNull();
});

test('a user who cannot see the issue cannot download the file', function () {
    ['field' => $field, 'issue' => $issue] = attachmentFieldSetup();
    $media = attachFieldFile($issue, $field);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('attachments.show', $media))->assertForbidden();
    $this->actingAs($outsider)->get(route('attachments.preview', $media))->assertForbidden();

    Passport::actingAs($outsider);
    $this->getJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    $this->get("/api/v1/attachments/{$media->id}/download")->assertForbidden();
});

test('a member whose role cannot see the field cannot download its file', function () {
    ['field' => $field, 'issue' => $issue, 'project' => $project] = attachmentFieldSetup();
    $field->roles()->attach(Role::factory()->create());
    $media = attachFieldFile($issue, $field);

    $member = User::factory()->create();
    Member::factory()->for($project)->for($member)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_issues']]));

    $this->actingAs($member)->get(route('issues.show', [$project, $issue]))->assertOk()->assertDontSee('spec.pdf');
    $this->actingAs($member)->get(route('attachments.show', $media))->assertForbidden();

    Passport::actingAs($member);
    $this->get("/api/v1/attachments/{$media->id}/download")->assertForbidden();
});

test('the attachments api cannot rename or delete a field file', function () {
    ['field' => $field, 'issue' => $issue, 'member' => $member] = attachmentFieldSetup();
    $media = attachFieldFile($issue, $field);

    Passport::actingAs($member);
    $this->getJson("/api/v1/attachments/{$media->id}")->assertOk();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    $this->putJson("/api/v1/attachments/{$media->id}", ['filename' => 'x.pdf'])->assertForbidden();

    expect(Media::query()->find($media->id))->not->toBeNull();
});

test('the rest api sets the value from an upload token and returns the file id', function () {
    ['field' => $field, 'issue' => $issue, 'member' => $member] = attachmentFieldSetup();
    Passport::actingAs($member);

    $token = $this->call('POST', '/api/v1/uploads?filename=api.pdf', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], 'pdf bytes')
        ->assertCreated()->json('upload.token');

    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $field->id, 'value' => ['token' => $token]]]])->assertSuccessful();

    $media = $issue->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();
    expect($media->file_name)->toBe('api.pdf');

    $this->getJson("/api/v1/issues/{$issue->id}")
        ->assertOk()
        ->assertJsonFragment(['id' => $field->id, 'name' => 'Spec sheet', 'value' => (string) $media->id]);

    $this->putJson("/api/v1/issues/{$issue->id}", ['custom_fields' => [['id' => $field->id, 'value' => ['token' => '1.00000000-0000-0000-0000-000000000000']]]])
        ->assertUnprocessable();
});

test('the field\'s allowed extensions are enforced', function () {
    ['project' => $project, 'field' => $field, 'issue' => $issue, 'member' => $member] = attachmentFieldSetup(['format_options' => ['extensions_allowed' => 'pdf, png']]);

    Livewire::actingAs($member)->test('issues.form', ['project' => $project, 'issue' => $issue])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('notes.txt', 10))
        ->call('save')
        ->assertHasErrors("customFieldValues.{$field->id}");

    expect($issue->fresh()->getMedia(AttachmentFormat::COLLECTION))->toHaveCount(0);
});

test('copying an issue does not copy the file', function () {
    ['field' => $field, 'issue' => $issue, 'project' => $project, 'tracker' => $tracker] = attachmentFieldSetup();
    attachFieldFile($issue, $field);

    $copy = app(IssueService::class)->copy($issue->fresh(), $project, $tracker->id, User::factory()->admin()->create());

    expect($copy->fresh()->load('customFieldValues')->customValue($field))->toBeNull()
        ->and($copy->fresh()->getMedia(AttachmentFormat::COLLECTION))->toHaveCount(0);
});

test('an attachment field is offered for issues only and is never multiple or a filter', function () {
    $admin = User::factory()->admin()->create();
    $tracker = Tracker::factory()->create();

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('name', 'Drawing')
        ->set('field_format', 'attachment')
        ->set('multiple', true)
        ->set('is_filter', true)
        ->set('extensionsAllowed', 'pdf')
        ->set('trackerIds', [$tracker->id])
        ->call('save')
        ->assertHasNoErrors();

    $field = CustomField::query()->where('name', 'Drawing')->sole();
    expect($field->multiple)->toBeFalse()
        ->and($field->is_filter)->toBeFalse()
        ->and($field->format_options)->toBe(['extensions_allowed' => 'pdf']);

    Livewire::actingAs($admin)->test('custom-fields.form')
        ->set('customized_type', 'project')
        ->set('name', 'Project drawing')
        ->set('field_format', 'attachment')
        ->call('save')
        ->assertHasErrors('field_format');
});
