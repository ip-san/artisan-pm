<?php

use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Member;
use App\Models\PendingUpload;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

function attachmentApiMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function attachmentApiIssue(Project $project, ?User $uploader = null, string $name = 'report.txt'): array
{
    $issue = Issue::factory()->for($project)->create([
        'tracker_id' => Tracker::factory()->create()->id,
        'status_id' => IssueStatus::factory()->create()->id,
        'priority_id' => Enumeration::factory()->create()->id,
    ]);

    if ($uploader !== null) {
        test()->actingAs($uploader);
    }

    $media = $issue->addMediaFromString('file body')->usingFileName($name)->toMediaCollection('attachments');
    auth()->logout();

    return [$issue, $media];
}

test('the attachment endpoints need authentication', function () {
    [, $media] = attachmentApiIssue(Project::factory()->create());

    $this->getJson("/api/v1/attachments/{$media->id}")->assertUnauthorized();
    $this->getJson("/api/v1/attachments/{$media->id}/download")->assertUnauthorized();
    $this->putJson("/api/v1/attachments/{$media->id}", ['description' => 'x'])->assertUnauthorized();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertUnauthorized();
});

test('a viewer can read an attachment with its uploader and download counter', function () {
    $project = Project::factory()->create();
    $viewer = attachmentApiMember($project, ['view_issues']);
    $uploader = User::factory()->create(['name' => 'Uploader Ann']);
    [, $media] = attachmentApiIssue($project, $uploader);

    Passport::actingAs($viewer);

    $this->getJson("/api/v1/attachments/{$media->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $media->id)
        ->assertJsonPath('data.filename', 'report.txt')
        ->assertJsonPath('data.author', ['id' => $uploader->id, 'name' => 'Uploader Ann'])
        ->assertJsonPath('data.downloads', 0)
        ->assertJsonStructure(['data' => ['filesize', 'content_type', 'description', 'content_url', 'download_url', 'created_at']]);
});

test('an attachment of an issue the caller cannot see is forbidden', function () {
    $project = Project::factory()->create();
    $outsider = User::factory()->create();
    [, $media] = attachmentApiIssue($project);
    $private = Project::factory()->private()->create();
    [, $hidden] = attachmentApiIssue($private);

    Passport::actingAs($outsider);

    $this->getJson("/api/v1/attachments/{$hidden->id}")->assertForbidden();
    $this->getJson("/api/v1/attachments/{$hidden->id}/download")->assertForbidden();
    expect($media->id)->toBeInt();
});

test('downloading streams the file, counts the download, and works with a token', function () {
    $project = Project::factory()->create();
    $viewer = attachmentApiMember($project, ['view_issues']);
    [, $media] = attachmentApiIssue($project);

    Passport::actingAs($viewer);

    $response = $this->get("/api/v1/attachments/{$media->id}/download");

    $response->assertOk()->assertDownload('report.txt');
    expect((int) $media->fresh()->getCustomProperty('download_count'))->toBe(1);
});

test('only someone who may edit the owner can change or delete an attachment', function () {
    $project = Project::factory()->create();
    $viewer = attachmentApiMember($project, ['view_issues']);
    $editor = attachmentApiMember($project, ['view_issues', 'edit_issues']);
    [, $media] = attachmentApiIssue($project);

    Passport::actingAs($viewer);
    $this->putJson("/api/v1/attachments/{$media->id}", ['description' => 'nope'])->assertForbidden();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    expect($media->fresh())->not->toBeNull();

    Passport::actingAs($editor);
    $this->putJson("/api/v1/attachments/{$media->id}", ['description' => 'Quarterly report', 'filename' => 'q3.txt'])->assertNoContent();

    expect($media->fresh()->getCustomProperty('description'))->toBe('Quarterly report')
        ->and($media->fresh()->file_name)->toBe('q3.txt');
});

test('PATCH works like PUT and a blank description clears it', function () {
    $project = Project::factory()->create();
    $editor = attachmentApiMember($project, ['view_issues', 'edit_issues']);
    [, $media] = attachmentApiIssue($project);
    $media->setCustomProperty('description', 'old');
    $media->save();

    Passport::actingAs($editor);

    $this->patchJson("/api/v1/attachments/{$media->id}", ['description' => '  '])->assertNoContent();

    expect($media->fresh()->getCustomProperty('description'))->toBeNull();
});

test('a renamed file must keep an allowed extension and cannot smuggle a path', function () {
    $project = Project::factory()->create();
    $editor = attachmentApiMember($project, ['view_issues', 'edit_issues']);
    [, $media] = attachmentApiIssue($project);
    App\Models\Setting::set('attachment_extensions_denied', 'exe');

    Passport::actingAs($editor);

    $this->putJson("/api/v1/attachments/{$media->id}", ['filename' => 'virus.exe'])->assertUnprocessable();
    $this->putJson("/api/v1/attachments/{$media->id}", ['filename' => '../../etc/notes.txt'])->assertNoContent();

    expect($media->fresh()->file_name)->toBe('notes.txt');
});

test('deleting an issue attachment removes it and records the removal in the journal', function () {
    $project = Project::factory()->create();
    $editor = attachmentApiMember($project, ['view_issues', 'edit_issues']);
    [$issue, $media] = attachmentApiIssue($project);

    Passport::actingAs($editor);

    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertNoContent();

    $detail = Journal::query()->where('issue_id', $issue->id)->firstOrFail()->details->first();

    expect(App\Models\Issue::find($issue->id)->getMedia('attachments'))->toHaveCount(0)
        ->and($detail->property)->toBe('attachment')
        ->and($detail->old_value)->toBe('report.txt');
});

test('attachments of other containers follow their own owner policy', function () {
    $project = Project::factory()->create();
    $reader = attachmentApiMember($project, ['view_documents']);
    $writer = attachmentApiMember($project, ['view_documents', 'edit_documents']);
    $document = Document::factory()->for($project)->create();
    $media = $document->addMediaFromString('doc')->usingFileName('spec.pdf')->toMediaCollection('attachments');

    Passport::actingAs($reader);
    $this->getJson("/api/v1/attachments/{$media->id}")->assertOk();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertForbidden();

    Passport::actingAs($writer);
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertNoContent();
    expect($document->fresh()->getMedia('attachments'))->toHaveCount(0);
});

test('a pending upload is not reachable through the attachment endpoints', function () {
    $user = User::factory()->create();
    $pending = PendingUpload::create(['user_id' => $user->id]);
    $media = $pending->addMediaFromString('tmp')->usingFileName('tmp.bin')->toMediaCollection('pending');

    Passport::actingAs($user);

    $this->getJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    expect($media->fresh())->not->toBeNull();
});

test('an unknown attachment is a 404', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/attachments/999999')->assertNotFound();
});

test('the issue payload lists attachments with their author and download url, without per-file queries', function () {
    $project = Project::factory()->create();
    $viewer = attachmentApiMember($project, ['view_issues']);
    $uploader = User::factory()->create(['name' => 'Ann']);
    [$issue] = attachmentApiIssue($project, $uploader, 'one.txt');
    test()->actingAs($uploader);
    foreach (['two.txt', 'three.txt', 'four.txt'] as $name) {
        $issue->addMediaFromString('x')->usingFileName($name)->toMediaCollection('attachments');
    }
    auth()->logout();

    Passport::actingAs($viewer);

    $queries = 0;
    Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
        $queries++;
    });

    $attachments = $this->getJson("/api/v1/issues/{$issue->id}?include=attachments")->assertOk()->json('data.attachments');

    expect($attachments)->toHaveCount(4)
        ->and($attachments[0]['author']['name'])->toBe('Ann')
        ->and($attachments[0])->toHaveKeys(['download_url', 'downloads'])
        ->and($queries)->toBeLessThan(30);
});
