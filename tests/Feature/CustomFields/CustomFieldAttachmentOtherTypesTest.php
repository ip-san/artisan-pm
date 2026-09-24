<?php

use App\CustomFields\Formats\AttachmentFormat;
use App\Enums\CustomFieldFormat;
use App\Enums\CustomizableType;
use App\Enums\EnumerationType;
use App\Enums\TimeEntryVisibility;
use App\Enums\UsersVisibility;
use App\Models\CustomField;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Group;
use App\Models\Issue;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

function otherTypeAttachmentField(CustomizableType $type, array $attributes = []): CustomField
{
    return CustomField::factory()->create(['name' => 'Drawing', 'customized_type' => $type, 'field_format' => CustomFieldFormat::Attachment, ...$attributes]);
}

/**
 * @param  array<int, string>  $permissions
 */
function otherTypeMember(Project $project, array $permissions, array $roleAttributes = []): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions, ...$roleAttributes]));

    return $user;
}

function otherTypeAttach(Model $record, CustomField $field, string $name = 'drawing.pdf'): Media
{
    $record->setCustomFieldValues([$field->id => UploadedFile::fake()->create($name, 10)], collect([$field]));

    return Media::query()->where('collection_name', AttachmentFormat::COLLECTION)->latest('id')->firstOrFail();
}

test('the attachment format is offered for every customizable type', function (CustomizableType $type) {
    Livewire::actingAs(User::factory()->admin()->create())->test('custom-fields.form')
        ->set('customized_type', $type->value)
        ->set('name', 'File '.$type->value)
        ->set('field_format', 'attachment')
        ->call('save')
        ->assertHasNoErrors();

    expect(CustomField::query()->where('name', 'File '.$type->value)->sole()->field_format)->toBe(CustomFieldFormat::Attachment);
})->with(array_values(array_filter(CustomizableType::cases(), fn (CustomizableType $type) => $type !== CustomizableType::Issue)));

test('the project settings form uploads the file, which project members can download and outsiders cannot', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $project->trackers()->sync([Tracker::factory()->create()->id]);
    $field = otherTypeAttachmentField(CustomizableType::Project);
    $member = otherTypeMember($project, ['view_project']);

    Livewire::actingAs(User::factory()->admin()->create())->test('projects.form', ['project' => $project])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('plan.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    $media = $project->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();
    expect($project->fresh()->load('customFieldValues')->customValue($field))->toBe($media->id)
        ->and($project->fresh()->getMedia('files'))->toHaveCount(0);

    $this->actingAs($member)->get(route('attachments.show', $media))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('attachments.show', $media))->assertForbidden();
});

test('a project member whose roles cannot see the field cannot download its file', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $field = otherTypeAttachmentField(CustomizableType::Project);
    $field->roles()->attach(Role::factory()->create());
    $media = otherTypeAttach($project, $field);

    $member = otherTypeMember($project, ['view_project']);

    $this->actingAs($member)->get(route('attachments.show', $media))->assertForbidden();

    Passport::actingAs($member);
    $this->get("/api/v1/attachments/{$media->id}/download")->assertForbidden();
});

test('a version file follows the roadmap permission, not the files module', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $version = Version::factory()->for($project)->create();
    $field = otherTypeAttachmentField(CustomizableType::Version);
    $media = otherTypeAttach($version, $field);

    $this->actingAs(otherTypeMember($project, ['view_issues']))->get(route('attachments.show', $media))->assertOk();
    $this->actingAs(otherTypeMember($project, ['view_files']))->get(route('attachments.show', $media))->assertForbidden();
});

test('the version form uploads a file', function () {
    $project = Project::factory()->create();
    $version = Version::factory()->for($project)->create();
    $field = otherTypeAttachmentField(CustomizableType::Version);

    Livewire::actingAs(otherTypeMember($project, ['manage_versions', 'view_issues']))->test('versions.form', ['project' => $project, 'version' => $version])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('release.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    expect($version->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole()->file_name)->toBe('release.pdf')
        ->and($version->fresh()->getMedia('files'))->toHaveCount(0);
});

test('a document file needs view_documents and shows as a link on the document', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $document = Document::factory()->for($project)->create();
    $field = otherTypeAttachmentField(CustomizableType::Document);
    $reader = otherTypeMember($project, ['view_documents']);

    Livewire::actingAs(otherTypeMember($project, ['view_documents', 'edit_documents']))->test('documents.form', ['project' => $project, 'document' => $document])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('scan.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    $media = $document->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();
    expect($document->fresh()->getMedia('attachments'))->toHaveCount(0);

    $this->actingAs($reader)->get(route('documents.show', [$project, $document]))->assertOk()->assertSee(route('attachments.show', $media), false);
    $this->actingAs($reader)->get(route('attachments.show', $media))->assertOk();
    $this->actingAs(otherTypeMember($project, ['view_issues']))->get(route('attachments.show', $media))->assertForbidden();
});

test('a time entry file is hidden from members who only see their own time entries', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $owner = otherTypeMember($project, ['view_time_entries', 'log_time']);
    $entry = TimeEntry::factory()->for($project)->create(['user_id' => $owner->id]);
    $field = otherTypeAttachmentField(CustomizableType::TimeEntry);
    $media = otherTypeAttach($entry, $field);

    $this->actingAs($owner)->get(route('attachments.show', $media))->assertOk();
    $this->actingAs(otherTypeMember($project, ['view_time_entries'], ['time_entries_visibility' => TimeEntryVisibility::Own]))
        ->get(route('attachments.show', $media))->assertForbidden();
});

test('the time entry form uploads a file', function () {
    $project = Project::factory()->create();
    $user = otherTypeMember($project, ['view_time_entries', 'log_time']);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $field = otherTypeAttachmentField(CustomizableType::TimeEntry);

    Livewire::actingAs($user)->test('time-entries.form', ['project' => $project])
        ->set('hours', '1')
        ->set('activity_id', $activity->id)
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('receipt.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    $entry = TimeEntry::query()->where('user_id', $user->id)->sole();
    expect($entry->getMedia(AttachmentFormat::COLLECTION)->sole()->file_name)->toBe('receipt.pdf');
});

test('a user file follows the user visibility rule', function () {
    Setting::set('login_required', true);
    $target = User::factory()->create();
    $field = otherTypeAttachmentField(CustomizableType::User);
    $media = otherTypeAttach($target, $field);

    $project = Project::factory()->create(['is_public' => false]);
    Member::factory()->for($project)->for($target)->create()->roles()->attach(Role::factory()->create(['permissions' => ['view_project']]));
    $colleague = otherTypeMember($project, ['view_project'], ['users_visibility' => UsersVisibility::MembersOfVisibleProjects]);

    $stranger = User::factory()->create();
    Member::factory()->for(Project::factory()->create(['is_public' => false]))->for($stranger)->create()
        ->roles()->attach(Role::factory()->create(['permissions' => ['view_project'], 'users_visibility' => UsersVisibility::MembersOfVisibleProjects]));

    $this->actingAs($colleague)->get(route('attachments.show', $media))->assertOk();
    $this->actingAs($stranger)->get(route('attachments.show', $media))->assertForbidden();
});

test('the admin user form uploads a user file', function () {
    $user = User::factory()->create();
    $field = otherTypeAttachmentField(CustomizableType::User);

    Livewire::actingAs(User::factory()->admin()->create())->test('users.form', ['user' => $user])
        ->set("customFieldValues.{$field->id}", UploadedFile::fake()->create('cv.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole()->file_name)->toBe('cv.pdf');
});

test('group and enumeration files are uploaded by administrators and only they can download them', function () {
    $admin = User::factory()->admin()->create();
    $group = Group::factory()->create();
    $groupField = otherTypeAttachmentField(CustomizableType::Group);
    $activity = Enumeration::factory()->create(['type' => EnumerationType::TimeEntryActivity->value]);
    $activityField = otherTypeAttachmentField(CustomizableType::TimeEntryActivity, ['name' => 'Guide']);

    Livewire::actingAs($admin)->test('groups.form', ['group' => $group])
        ->set("customFieldValues.{$groupField->id}", UploadedFile::fake()->create('charter.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test('enumerations.form', ['type' => EnumerationType::TimeEntryActivity, 'enumeration' => $activity])
        ->set("customFieldValues.{$activityField->id}", UploadedFile::fake()->create('guide.pdf', 10))
        ->call('save')
        ->assertHasNoErrors();

    $groupMedia = $group->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();
    $activityMedia = $activity->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();

    $member = User::factory()->create();
    $group->users()->attach($member);

    foreach ([$groupMedia, $activityMedia] as $media) {
        $this->actingAs($admin)->get(route('attachments.show', $media))->assertOk();
        $this->actingAs($member)->get(route('attachments.show', $media))->assertForbidden();
    }
});

test('another record\'s file id is never taken over', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $projectField = otherTypeAttachmentField(CustomizableType::Project);
    $issueField = CustomField::factory()->create(['field_format' => CustomFieldFormat::Attachment]);
    $issue = Issue::factory()->for($project)->create();

    $own = otherTypeAttach($project, $projectField, 'own.pdf');
    $foreignProjectFile = otherTypeAttach($otherProject, $projectField, 'foreign.pdf');
    $issueFile = otherTypeAttach($issue, $issueField, 'issue.pdf');

    foreach ([$foreignProjectFile, $issueFile] as $foreign) {
        $project->setCustomFieldValues([$projectField->id => (string) $foreign->id], collect([$projectField]));

        expect($project->fresh()->load('customFieldValues')->customValue($projectField))->toBe($own->id)
            ->and(Media::query()->find($foreign->id))->not->toBeNull();
    }
});

test('the rest api sets project and time entry files from upload tokens, and the attachments api cannot change them', function () {
    $admin = User::factory()->admin()->create();
    $project = Project::factory()->create();
    $entry = TimeEntry::factory()->for($project)->create();
    $projectField = otherTypeAttachmentField(CustomizableType::Project);
    $entryField = otherTypeAttachmentField(CustomizableType::TimeEntry, ['name' => 'Receipt']);
    Passport::actingAs($admin);

    $upload = fn (string $name) => $this->call('POST', '/api/v1/uploads?filename='.$name, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/octet-stream'], 'bytes')
        ->assertCreated()->json('upload.token');

    $this->putJson("/api/v1/projects/{$project->id}", ['custom_fields' => [['id' => $projectField->id, 'value' => ['token' => $upload('plan.pdf')]]]])->assertSuccessful();
    $this->putJson("/api/v1/time_entries/{$entry->id}", ['custom_fields' => [['id' => $entryField->id, 'value' => ['token' => $upload('receipt.pdf')]]]])->assertSuccessful();

    $projectMedia = $project->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();
    $entryMedia = $entry->fresh()->getMedia(AttachmentFormat::COLLECTION)->sole();

    expect($projectMedia->file_name)->toBe('plan.pdf')
        ->and($entryMedia->file_name)->toBe('receipt.pdf');

    $this->getJson("/api/v1/projects/{$project->id}")->assertJsonFragment(['id' => $projectField->id, 'value' => (string) $projectMedia->id]);

    $this->deleteJson("/api/v1/attachments/{$projectMedia->id}")->assertForbidden();
    $this->putJson("/api/v1/attachments/{$entryMedia->id}", ['filename' => 'x.pdf'])->assertForbidden();
    expect(Media::query()->whereKey([$projectMedia->id, $entryMedia->id])->count())->toBe(2);
});

test('self-registration takes a file for an attachment user field', function () {
    Setting::set('self_registration', 'automatic');
    $field = otherTypeAttachmentField(CustomizableType::User, ['is_required' => true]);

    $this->get(route('register'))->assertOk()->assertSee('enctype="multipart/form-data"', false);

    $this->post(route('register'), [
        'login' => 'newbie',
        'name' => 'New Bie',
        'email' => 'newbie@example.com',
        'password' => 'Password123!secure',
        'password_confirmation' => 'Password123!secure',
        'custom_fields' => [$field->id => UploadedFile::fake()->create('id.pdf', 10)],
    ]);

    $user = User::query()->where('login', 'newbie')->sole();
    expect($user->getMedia(AttachmentFormat::COLLECTION)->sole()->file_name)->toBe('id.pdf');
});

test('deleting a record with a field file deletes the file', function () {
    $group = Group::factory()->create();
    $field = otherTypeAttachmentField(CustomizableType::Group);
    $media = otherTypeAttach($group, $field);

    $group->delete();

    expect(Media::query()->find($media->id))->toBeNull();
});
