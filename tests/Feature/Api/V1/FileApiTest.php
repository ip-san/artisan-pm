<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;

function fileApiMember(Project $project, array $permissions): User
{
    $user = User::factory()->create();
    Member::factory()->for($project)->for($user)->create()->roles()->attach(Role::factory()->create(['permissions' => $permissions]));

    return $user;
}

function fileApiToken(string $name = 'release.zip'): string
{
    return test()->call('POST', "/api/v1/uploads?filename={$name}", server: ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], content: 'zip-bytes')
        ->assertCreated()->json('upload.token');
}

test('the file endpoints need authentication', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/projects/{$project->id}/files")->assertUnauthorized();
    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => 'x'])->assertUnauthorized();
});

test('the list shows project files and version files with the version, author and downloads', function () {
    $project = Project::factory()->create();
    $viewer = fileApiMember($project, ['view_files']);
    $uploader = User::factory()->create(['name' => 'Publisher']);
    $version = Version::factory()->for($project)->create(['name' => '1.0']);
    test()->actingAs($uploader);
    $project->addMediaFromString('p')->usingFileName('project.zip')->toMediaCollection('files');
    $version->addMediaFromString('v')->usingFileName('v1.zip')->toMediaCollection('files');
    auth()->logout();

    Passport::actingAs($viewer);

    $files = collect($this->getJson("/api/v1/projects/{$project->id}/files")->assertOk()->json('data'))->keyBy('filename');

    expect($files->keys()->sort()->values()->all())->toBe(['project.zip', 'v1.zip'])
        ->and($files['project.zip'])->not->toHaveKey('version')
        ->and($files['v1.zip']['version'])->toBe(['id' => $version->id, 'name' => '1.0'])
        ->and($files['v1.zip']['author']['name'])->toBe('Publisher')
        ->and($files['v1.zip'])->toHaveKeys(['downloads', 'download_url', 'filesize']);
});

test('listing needs view_files', function () {
    $project = Project::factory()->create();
    $noFiles = fileApiMember($project, ['view_issues']);
    $outsider = User::factory()->create();

    Passport::actingAs($noFiles);
    $this->getJson("/api/v1/projects/{$project->id}/files")->assertForbidden();

    Passport::actingAs($outsider);
    $this->getJson("/api/v1/projects/{$project->id}/files")->assertForbidden();
});

test('a member with manage_files attaches an uploaded file to the project', function () {
    $project = Project::factory()->create();
    $manager = fileApiMember($project, ['view_files', 'manage_files']);

    Passport::actingAs($manager);
    $token = fileApiToken();

    $response = $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => $token, 'filename' => 'release-1.zip', 'description' => 'First release'])
        ->assertCreated()
        ->assertJsonPath('data.filename', 'release-1.zip')
        ->assertJsonPath('data.description', 'First release')
        ->assertJsonPath('data.author.id', $manager->id);

    $files = $project->fresh()->files();
    expect($files)->toHaveCount(1)
        ->and($files->first()->id)->toBe($response->json('data.id'));
});

test('both Redmine body shapes work and a version can be the target', function () {
    $project = Project::factory()->create();
    $manager = fileApiMember($project, ['view_files', 'manage_files']);
    $version = Version::factory()->for($project)->create();

    Passport::actingAs($manager);

    $this->postJson("/api/v1/projects/{$project->id}/files", ['file' => ['token' => fileApiToken('a.zip'), 'version_id' => $version->id]])->assertCreated();
    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => fileApiToken('b.zip'), 'version_id' => $version->id])->assertCreated();

    expect($version->fresh()->files())->toHaveCount(2)
        ->and($project->fresh()->files())->toHaveCount(0);
});

test('a version of another project is not a valid target', function () {
    $project = Project::factory()->create();
    $manager = fileApiMember($project, ['view_files', 'manage_files']);
    $foreign = Version::factory()->for(Project::factory()->create())->create();

    Passport::actingAs($manager);
    $token = fileApiToken();

    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => $token, 'version_id' => $foreign->id])->assertNotFound();
    expect($foreign->fresh()->files())->toHaveCount(0);
});

test('attaching needs manage_files and a valid token', function () {
    $project = Project::factory()->create();
    $reader = fileApiMember($project, ['view_files']);
    $manager = fileApiMember($project, ['view_files', 'manage_files']);

    Passport::actingAs($reader);
    $token = fileApiToken();
    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => $token])->assertForbidden();

    Passport::actingAs($manager);
    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => 'not-a-token'])->assertUnprocessable();
    $this->postJson("/api/v1/projects/{$project->id}/files", [])->assertUnprocessable();
    expect($project->fresh()->files())->toHaveCount(0);
});

test('a renamed file keeps the extension rules and a token cannot be redeemed twice', function () {
    $project = Project::factory()->create();
    $manager = fileApiMember($project, ['view_files', 'manage_files']);
    App\Models\Setting::set('attachment_extensions_denied', 'exe');

    Passport::actingAs($manager);
    $token = fileApiToken('good.zip');

    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => $token, 'filename' => 'evil.exe'])->assertCreated();
    expect($project->fresh()->files()->first()->file_name)->toBe('good.zip');

    $this->postJson("/api/v1/projects/{$project->id}/files", ['token' => $token])->assertUnprocessable();
    expect($project->fresh()->files())->toHaveCount(1);
});

test('a Files-module attachment is read through view_files, not just visibility of the project', function () {
    $project = Project::factory()->create();
    $projectViewerOnly = fileApiMember($project, ['view_project', 'view_issues']);
    $filesViewer = fileApiMember($project, ['view_files']);
    $manager = fileApiMember($project, ['view_files', 'manage_files']);
    $media = $project->addMediaFromString('p')->usingFileName('project.zip')->toMediaCollection('files');

    Passport::actingAs($projectViewerOnly);
    $this->getJson("/api/v1/attachments/{$media->id}")->assertForbidden();
    $this->get("/api/v1/attachments/{$media->id}/download")->assertForbidden();

    Passport::actingAs($filesViewer);
    $this->getJson("/api/v1/attachments/{$media->id}")->assertOk();
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertForbidden();

    Passport::actingAs($manager);
    $this->deleteJson("/api/v1/attachments/{$media->id}")->assertNoContent();
});
